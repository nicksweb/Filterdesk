<?php

namespace Tests\Feature;

use App\Enums\AccessScope;
use App\Enums\Audience;
use App\Enums\RequestStatus;
use App\Models\ControllerPush;
use App\Models\FilterPolicy;
use App\Models\IntegrationConfig;
use App\Models\User;
use App\Notifications\FilterSyncAlertNotification;
use App\Services\Network\MockNetworkProvider;
use App\Services\Policy\PolicyEngine;
use App\Services\Requests\RequestWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
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
        $this->students = FilterPolicy::query()->where('audience', Audience::Student)->firstOrFail();
        $this->staff = FilterPolicy::query()->where('audience', Audience::Staff)->firstOrFail();
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
        $this->engine->upsertEntry($this->engine->defaultAllowList(Audience::Staff), 'staffonly.example.org', ['source' => 'manual']);

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
        $this->engine->upsertEntry($this->engine->defaultAllowList(Audience::Student), 'class.example.org', ['source' => 'manual']);

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

        $request = $workflow->approve($this->pendingRequest(['live.example.org'], AccessScope::Staff), $it);

        $this->assertSame(RequestStatus::Active, $request->status);
        $this->assertContains('live.example.org', $this->filter('sim-filter-staff')['allow']);
        $this->assertNotContains('live.example.org', $this->filter('sim-filter-students')['allow'], 'Staff-only access never reaches the student filter');

        $workflow->revoke($request, $it, 'No longer needed.');
        $this->assertNotContains('live.example.org', $this->filter('sim-filter-staff')['allow']);
    }

    public function test_rollback_restores_the_previous_lists_unless_the_filter_changed_since(): void
    {
        $admin = User::factory()->admin()->create();
        $this->engine->upsertEntry($this->engine->defaultAllowList(Audience::Student), 'oops.example.org', ['source' => 'manual']);
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
        $it = User::factory()->it()->create();
        $this->engine->upsertEntry($this->engine->defaultAllowList(Audience::Student), 'managed.example.org', ['source' => 'manual']);
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
        $this->assertStringContainsString('no longer exists', $this->students->sync_details['summary']);
    }
}
