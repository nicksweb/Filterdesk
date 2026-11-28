<?php

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\RequestStatus;
use App\Jobs\PushAfterRequestChange;
use App\Models\ControllerPush;
use App\Models\FilterPolicy;
use App\Models\IntegrationConfig;
use App\Models\PolicyEntry;
use App\Models\PolicyList;
use App\Models\User;
use App\Notifications\FilterSyncAlertNotification;
use App\Services\Network\MockNetworkProvider;
use App\Services\Policy\PolicyEngine;
use App\Services\Requests\RequestWorkflow;
use App\Services\SettingsRepository;
use App\Support\Reach;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

/**
 * Pushing to controller content filters, against the simulation provider:
 * Students → "Students" filter (student VLAN), Staff → "Staff" filter.
 */
class ControllerPushTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    private PolicyEngine $engine;

    private FilterPolicy $students;

    private FilterPolicy $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::flush();
        $this->engine = $this->installWithPolicies('active');
        $this->students = FilterPolicy::query()->where('audience', 'student')->firstOrFail();
        $this->staff = FilterPolicy::query()->where('audience', 'staff')->firstOrFail();
        $this->students->update(['provider_reference' => 'sim-filter-students']);
        $this->staff->update(['provider_reference' => 'sim-filter-staff']);
    }

    /** @return array<string, mixed> */
    private function filter(string $id): array
    {
        return collect((new MockNetworkProvider)->contentFilters())->firstWhere('id', $id);
    }

    public function test_push_applies_each_policys_change_set_to_its_own_filter_and_keeps_hand_made_entries(): void
    {
        $it = User::factory()->it()->create();
        $this->engine->upsertEntry($this->engine->defaultAllowList(null), 'everyone.example.org', ['source' => 'manual']);
        $this->engine->upsertEntry($this->engine->defaultAllowList('staff'), 'staffonly.example.org', ['source' => 'manual']);

        $this->actingAs($it)->post(route('admin.policy.networks.push'))->assertSessionHas('success');

        $this->assertSame(['example.edu', 'everyone.example.org'], $this->filter('sim-filter-students')['allow'], 'The hand-made example.edu entry is kept');
        $this->assertEqualsCanonicalizing(['everyone.example.org', 'staffonly.example.org'], $this->filter('sim-filter-staff')['allow']);
        $this->assertSame(['ADULT'], $this->filter('sim-filter-students')['categories'], 'Only the lists change');
        $this->assertSame(2, ControllerPush::query()->where('status', 'ok')->count());
        $this->assertTrue($this->engine->reconcile($this->staff->refresh())['in_sync']);
    }

    public function test_pushing_again_without_changes_does_not_write(): void
    {
        $it = User::factory()->it()->create();
        $this->engine->upsertEntry($this->engine->defaultAllowList('student'), 'class.example.org', ['source' => 'manual']);

        $this->actingAs($it)->post(route('admin.policy.policies.push', $this->students))->assertSessionHas('success');
        $this->actingAs($it)->post(route('admin.policy.policies.push', $this->students))->assertSessionHas('success', fn ($message) => str_contains($message, 'already matches'));

        $this->assertSame(1, ControllerPush::query()->count());
    }

    public function test_manual_push_marks_waiting_requests_applied_once_every_target_filter_allows_them(): void
    {
        IntegrationConfig::query()->where('provider', 'mock')->update(['mode' => 'read_only']);
        $request = app(RequestWorkflow::class)->approve($this->pendingRequest(['lesson.example.org']), User::factory()->it()->create());
        $this->assertSame(RequestStatus::ActionRequired, $request->status);
        IntegrationConfig::query()->where('provider', 'mock')->update(['mode' => 'active']);

        $this->actingAs(User::factory()->it()->create())->post(route('admin.policy.networks.push'))->assertSessionHas('success');

        $this->assertSame(RequestStatus::Active, $request->refresh()->status);
        $this->assertContains('lesson.example.org', $this->filter('sim-filter-staff')['allow']);
        $this->assertTrue($request->events()->where('type', 'applied')->exists());
    }

    public function test_automatic_mode_pushes_on_approval_and_revocation(): void
    {
        IntegrationConfig::query()->where('provider', 'mock')->update(['configuration' => ['auto_push' => true]]);
        $it = User::factory()->it()->create();
        $workflow = app(RequestWorkflow::class);

        $request = $workflow->approve($this->pendingRequest(['live.example.org'], Reach::types('staff')), $it);

        $this->assertSame(RequestStatus::Active, $request->status);
        $this->assertContains('live.example.org', $this->filter('sim-filter-staff')['allow']);
        $this->assertNotContains('live.example.org', $this->filter('sim-filter-students')['allow'], 'Staff-only access never reaches the student filter');

        $workflow->revoke($request, $it, 'No longer needed.');
        $this->assertNotContains('live.example.org', $this->filter('sim-filter-staff')['allow']);
    }

    public function test_rollback_restores_the_previous_lists_unless_the_filter_changed_since(): void
    {
        $admin = User::factory()->admin()->create();
        $this->engine->upsertEntry($this->engine->defaultAllowList('student'), 'oops.example.org', ['source' => 'manual']);
        $this->actingAs($admin)->post(route('admin.policy.policies.push', $this->students));
        $push = ControllerPush::query()->firstOrFail();

        $this->actingAs($admin)->post(route('admin.policy.pushes.rollback', $push))->assertSessionHas('success');
        $this->assertSame(['example.edu'], $this->filter('sim-filter-students')['allow']);

        $this->actingAs($admin)->post(route('admin.policy.policies.push', $this->students));
        (new MockNetworkProvider)->writeContentFilterLists('sim-filter-students', ['changed-in-unifi.example.org'], []);
        $latest = ControllerPush::query()->whereNull('rollback_of_id')->latest('id')->firstOrFail();
        $this->actingAs($admin)->post(route('admin.policy.pushes.rollback', $latest))->assertSessionHas('error');
    }

    public function test_pushing_is_refused_when_read_only_or_when_two_policies_share_a_filter(): void
    {
        $it = User::factory()->it()->create();
        $this->staff->update(['provider_reference' => 'sim-filter-students']);
        $this->actingAs($it)->post(route('admin.policy.policies.push', $this->students))->assertSessionHas('error', fn ($message) => str_contains($message, 'shares its controller filter'));

        IntegrationConfig::query()->where('provider', 'mock')->update(['mode' => 'read_only']);
        $this->actingAs($it)->post(route('admin.policy.policies.push', $this->students))->assertSessionHas('error', fn ($message) => str_contains($message, 'Pushing is off'));
        $this->assertSame(0, ControllerPush::query()->count());
    }

    public function test_scheduled_push_only_runs_in_automatic_mode(): void
    {
        $this->engine->upsertEntry($this->engine->defaultAllowList(null), 'scheduled.example.org', ['source' => 'manual']);

        $this->artisan('policy:push')->expectsOutput('Automatic pushing is off.')->assertSuccessful();
        $this->assertNotContains('scheduled.example.org', $this->filter('sim-filter-students')['allow']);

        IntegrationConfig::query()->where('provider', 'mock')->update(['configuration' => ['auto_push' => true]]);
        $this->artisan('policy:push')->assertSuccessful();
        $this->assertContains('scheduled.example.org', $this->filter('sim-filter-students')['allow']);
    }

    public function test_sync_check_tells_pending_changes_apart_from_edits_made_in_the_controller(): void
    {
        IntegrationConfig::query()->where('provider', 'mock')->update(['configuration' => ['controller_authoritative' => false]]);
        app(SettingsRepository::class)->set('notifications.alerts', 'now');
        $it = User::factory()->it()->create();
        $this->engine->upsertEntry($this->engine->defaultAllowList('student'), 'managed.example.org', ['source' => 'manual']);
        $this->artisan('policy:check-sync')->assertSuccessful();
        $this->assertSame('changes_due', $this->students->refresh()->sync_status);

        $this->actingAs($it)->post(route('admin.policy.policies.push', $this->students))->assertSessionHas('success');
        $this->assertSame('in_sync', $this->students->refresh()->sync_status);

        (new MockNetworkProvider)->writeContentFilterLists('sim-filter-students', ['example.edu', 'sneaky.example.org'], ['blocked-by-hand.example.org']);
        $this->artisan('policy:check-sync')->assertSuccessful();

        $this->students->refresh();
        $this->assertSame('drift', $this->students->sync_status);
        $this->assertSame(['sneaky.example.org'], $this->students->sync_details['changed_in_controller']['allow_added']);
        $this->assertSame(['managed.example.org'], $this->students->sync_details['changed_in_controller']['allow_removed']);
        $this->assertSame(['blocked-by-hand.example.org'], $this->students->sync_details['changed_in_controller']['block_added']);
        Notification::assertSentTo($it, FilterSyncAlertNotification::class);

        $this->artisan('policy:check-sync')->assertSuccessful();
        Notification::assertSentToTimes($it, FilterSyncAlertNotification::class, 1);

        $this->actingAs($it)->get(route('dashboard'))->assertSee('Web filters need attention');
        $this->actingAs($it)->get(route('admin.policy.policies.show', $this->students))->assertSee('Edited directly in the controller')->assertSee('sneaky.example.org');

        $this->actingAs($it)->post(route('admin.policy.policies.accept-controller', $this->students))->assertSessionHas('success');
        $this->assertSame('changes_due', $this->students->refresh()->sync_status, 'The managed entry removed by hand is still due to be pushed back');

        $this->actingAs($it)->post(route('admin.policy.policies.push', $this->students));
        $this->assertSame('in_sync', $this->students->refresh()->sync_status);
        $this->assertContains('sneaky.example.org', $this->filter('sim-filter-students')['allow'], 'Hand-made entries survive the push');
    }

    public function test_an_unreadable_or_missing_filter_is_reported(): void
    {
        $this->students->update(['provider_reference' => 'deleted-in-unifi']);

        $this->artisan('policy:check-sync')->assertSuccessful();

        $this->assertSame('error', $this->students->refresh()->sync_status);
        $this->assertStringContainsString('was deleted. Delete this policy', $this->students->sync_details['summary']);
    }

    public function test_the_controller_is_the_source_of_truth_for_entries_added_or_removed_there(): void
    {
        IntegrationConfig::query()->where('provider', 'mock')->update(['configuration' => ['auto_push' => true]]);
        $it = User::factory()->it()->create();
        $request = app(RequestWorkflow::class)->approve($this->pendingRequest(['approved.example.org'], Reach::types('staff', 'student')), $it);
        $this->assertSame(RequestStatus::Active, $request->status);
        $this->assertSame(['example.edu'], PolicyList::query()->where('filter_policy_id', $this->students->id)->first()?->entries()->pluck('domain')->all(), 'Hand-made controller entries are pulled in before the first push');

        // While this app is unavailable, IT edits the student filter directly in UniFi.
        (new MockNetworkProvider)->writeContentFilterLists('sim-filter-students', ['example.edu', 'added-in-unifi.example.org'], ['blocked-in-unifi.example.org']);
        $this->artisan('policy:check-sync')->assertSuccessful();
        $this->students->refresh();

        $this->assertSame('in_sync', $this->students->sync_status);
        $this->assertSame(['added-in-unifi.example.org'], $this->students->sync_details['pulled']['allow_imported']);
        $this->assertSame(['approved.example.org'], $this->students->exclusions()['allow']);
        $desired = $this->engine->desired($this->students);
        $this->assertContains('added-in-unifi.example.org', $desired['allow']);
        $this->assertNotContains('approved.example.org', $desired['allow'], 'Removed in UniFi, so kept out of the student filter');
        $this->assertContains('approved.example.org', $this->engine->desired($this->staff)['allow'], 'Other filters are unaffected');
        $this->assertSame(['blocked-in-unifi.example.org'], $desired['block']);
        $this->assertTrue($request->events()->where('type', 'controller_removed')->exists());

        $this->artisan('policy:push')->assertSuccessful();
        $this->assertSame(['example.edu', 'added-in-unifi.example.org'], $this->filter('sim-filter-students')['allow'], 'Automatic pushes do not undo UniFi changes');

        $this->actingAs($it)->post(route('admin.policy.policies.restore', $this->students), ['kind' => 'allow', 'domains' => ['approved.example.org']])->assertSessionHas('success');
        $this->assertContains('approved.example.org', $this->filter('sim-filter-students')['allow']);
        $this->assertSame([], $this->students->refresh()->exclusions()['allow']);
    }

    public function test_blocking_in_unifi_something_allowed_here_keeps_the_block(): void
    {
        $this->engine->upsertEntry($this->engine->defaultAllowList('student'), 'video.example.org', ['source' => 'manual']);
        $this->actingAs(User::factory()->it()->create())->post(route('admin.policy.policies.push', $this->students));

        (new MockNetworkProvider)->writeContentFilterLists('sim-filter-students', ['example.edu', 'video.example.org'], ['video.example.org']);
        $this->artisan('policy:check-sync')->assertSuccessful();

        $desired = $this->engine->desired($this->students->refresh());
        $this->assertContains('video.example.org', $desired['block']);
        $this->assertNotContains('video.example.org', $desired['allow']);
    }

    public function test_an_entry_removed_in_unifi_and_added_back_there_is_no_longer_excluded(): void
    {
        $this->engine->upsertEntry($this->engine->defaultAllowList('student'), 'flaky.example.org', ['source' => 'manual']);
        $this->actingAs(User::factory()->it()->create())->post(route('admin.policy.policies.push', $this->students));

        (new MockNetworkProvider)->writeContentFilterLists('sim-filter-students', ['example.edu'], []);
        $this->artisan('policy:check-sync');
        $this->assertSame(['flaky.example.org'], $this->students->refresh()->exclusions()['allow']);

        (new MockNetworkProvider)->writeContentFilterLists('sim-filter-students', ['example.edu', 'flaky.example.org'], []);
        $this->artisan('policy:check-sync');
        $this->assertSame([], $this->students->refresh()->exclusions()['allow']);
        $this->assertSame('in_sync', $this->students->sync_status);
    }

    public function test_pushes_are_refused_over_the_list_size_limit_and_large_automatic_changes_wait(): void
    {
        IntegrationConfig::query()->where('provider', 'mock')->update(['configuration' => ['max_filter_entries' => 50, 'auto_push' => true, 'controller_authoritative' => true]]);
        $list = $this->engine->ownList($this->students, ListKind::Block);
        foreach (range(1, 60) as $index) {
            $this->engine->upsertEntry($list, "site{$index}.example.org", ['source' => 'manual']);
        }

        $this->actingAs(User::factory()->it()->create())->post(route('admin.policy.policies.push', $this->students))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'over the limit of 50'));
        $this->assertSame([], $this->filter('sim-filter-students')['block']);

        IntegrationConfig::query()->where('provider', 'mock')->update(['configuration' => ['max_filter_entries' => 5000, 'max_automatic_changes' => 200, 'auto_push' => true, 'controller_authoritative' => true]]);
        foreach (range(61, 260) as $index) {
            $this->engine->upsertEntry($list, "site{$index}.example.org", ['source' => 'manual']);
        }
        $this->artisan('policy:push');
        $this->assertSame([], $this->filter('sim-filter-students')['block'], 'Over 200 automatic changes wait for a person');

        $this->actingAs(User::factory()->it()->create())->post(route('admin.policy.policies.push', $this->students))->assertSessionHas('success');
        $this->assertCount(260, $this->filter('sim-filter-students')['block']);
    }

    public function test_entries_the_app_wrote_are_removed_from_the_controller_when_dropped_not_imported_back(): void
    {
        IntegrationConfig::query()->where('provider', 'mock')->update(['configuration' => ['controller_authoritative' => true]]);
        $it = User::factory()->it()->create();
        $siteBlock = PolicyList::query()->where('scope', 'site')->where('kind', 'block')->firstOrFail();
        $entry = $this->engine->upsertEntry($siteBlock, 'temporary-block.example.org', ['source' => 'manual']);
        $this->actingAs($it)->post(route('admin.policy.policies.push', $this->students));
        $this->assertContains('temporary-block.example.org', $this->filter('sim-filter-students')['block']);

        $entry->delete();
        $this->artisan('policy:check-sync');
        $this->assertSame([], $this->engine->reconcile($this->students->refresh())['block_review']);
        $this->actingAs($it)->post(route('admin.policy.policies.push', $this->students))->assertSessionHas('success');

        $this->assertNotContains('temporary-block.example.org', $this->filter('sim-filter-students')['block']);
        $this->assertSame(0, PolicyEntry::query()->where('domain', 'temporary-block.example.org')->count(), 'Not imported back as a hand-made entry');
    }

    public function test_an_unused_policy_with_no_filter_or_networks_does_not_hold_up_a_site_wide_request(): void
    {
        IntegrationConfig::query()->where('provider', 'mock')->update(['configuration' => ['auto_push' => true, 'controller_authoritative' => true]]);
        FilterPolicy::query()->create(['name' => 'Leftover', 'slug' => 'leftover', 'audience' => 'staff']);
        $admin = User::factory()->admin()->create();
        $request = $this->pendingRequest(['open.example.org']);

        app(RequestWorkflow::class)->approve($request, $admin, scope: Reach::siteWide());

        $this->assertSame(RequestStatus::Active, $request->refresh()->status);
    }

    public function test_pushes_after_a_request_change_run_in_the_background(): void
    {
        Queue::fake();
        IntegrationConfig::query()->where('provider', 'mock')->update(['configuration' => ['auto_push' => true, 'controller_authoritative' => true]]);
        $request = $this->pendingRequest(['later.example.org']);

        $this->actingAs(User::factory()->admin()->create())->post(route('it.requests.action', $request), ['action' => 'approve'])->assertSessionHas('success');

        Queue::assertPushed(PushAfterRequestChange::class, fn ($job) => $job->websiteRequestId === $request->id && $job->trigger === 'approval');
        $this->assertNotContains('later.example.org', $this->filter('sim-filter-students')['allow'], 'Nothing is pushed while the page waits');
    }
}
