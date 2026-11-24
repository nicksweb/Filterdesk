<?php

namespace Tests\Feature;

use App\Enums\EntrySource;
use App\Enums\ListKind;
use App\Models\FilterPolicy;
use App\Models\NetworkProfile;
use App\Models\PolicyEntry;
use App\Models\User;
use App\Services\Network\MockNetworkProvider;
use App\Services\Policy\PolicyEngine;
use App\Services\Policy\RunTogetherEntries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

/**
 * Tidying what earlier set-up runs left: converting a filter that was
 * brought in as the main Students policy into a school-hours layer hands
 * the name and probe profile to the all-hours policy and never merges the
 * two filters' lists; "Leave it alone" lets go of a filter; leftovers can
 * be deleted; entries typed without a line break are flagged and split.
 */
class SetupTidyTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    private PolicyEngine $engine;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::flush();
        $this->engine = $this->installWithPolicies('active');
        $this->admin = User::factory()->admin()->create();
    }

    /** As on docker03: School Time was the only Students filter at the first import, so it took the Students policy. */
    private function schoolTimeFirst(): array
    {
        Cache::forever('mock.content_filters', [
            ['id' => 'sim-school-time', 'name' => 'Student - School Time', 'enabled' => true, 'allow' => ['class-tool.example.org'], 'block' => ['games.example.net'], 'network_ids' => ['sim-student'], 'categories' => ['ADULT'], 'safe_search' => [], 'schedule' => 'EVERY_WEEK'],
            ['id' => 'sim-filter-staff', 'name' => 'Staff', 'enabled' => true, 'allow' => [], 'block' => [], 'network_ids' => ['sim-staff'], 'categories' => [], 'safe_search' => []],
        ]);
        $this->actingAs($this->admin)->post(route('admin.policy.setup.import-all'));
        $students = FilterPolicy::query()->where('provider_reference', 'sim-school-time')->sole();
        $this->assertSame('Students', $students->name);
        $students->update(['network_profile_id' => NetworkProfile::query()->where('key', 'student')->value('id')]);

        $filters = Cache::get('mock.content_filters');
        $filters[] = ['id' => 'sim-all-hours', 'name' => 'Students - All Hours', 'enabled' => true, 'allow' => ['example.edu', 'wistia.comfast.wistia.net'], 'block' => [], 'network_ids' => ['sim-student', 'sim-guest'], 'categories' => ['ADULT'], 'safe_search' => []];
        Cache::forever('mock.content_filters', $filters);

        return [$students, $filters];
    }

    public function test_converting_hands_the_name_and_probe_to_the_all_hours_policy_without_merging_lists(): void
    {
        [$students] = $this->schoolTimeFirst();
        // First import of All Hours as its own policy, as Bring everything in did on 2026.12.14.
        $this->actingAs($this->admin)->post(route('admin.policy.networks.link'), ['filter_id' => 'sim-all-hours', 'managed_by' => 'new:student']);
        $allHours = FilterPolicy::query()->where('provider_reference', 'sim-all-hours')->sole();

        // The "natural" but wrong pick: Students (which keeps School Time's lists) for All Hours.
        $this->actingAs($this->admin)->post(route('admin.policy.setup.store'), ['filters' => [
            'sim-school-time' => ['as' => 'hours:sim-all-hours', 'import' => '0'],
            'sim-all-hours' => ['as' => 'type:student', 'policy_id' => $students->id, 'import' => '1'],
            'sim-filter-staff' => ['as' => 'type:staff'],
        ]])->assertSessionHas('success', fn (string $message) => str_contains($message, 'not merged') && str_contains($message, 'Renamed'));

        $layer = $students->refresh();
        $parent = $allHours->refresh();
        $this->assertTrue($layer->isHoursLayer());
        $this->assertTrue($layer->layerOf->is($parent));
        $this->assertSame('Student - School Time', $layer->name);
        $this->assertSame('Students', $parent->name, 'The main policy keeps the main name');
        $this->assertSame(NetworkProfile::query()->where('key', 'student')->value('id'), $parent->network_profile_id, 'The probe profile moves to the main policy');
        $this->assertNull($layer->network_profile_id);
        $this->assertNotContains('games.example.net', $this->engine->desired($parent)['block'], "School Time's blocks stay school-hours only");
        $this->assertContains('games.example.net', $this->engine->desired($layer)['block']);
    }

    public function test_leave_it_alone_lets_go_and_leftovers_can_be_deleted(): void
    {
        $filters = (new MockNetworkProvider)->contentFilters();
        $filters[] = ['id' => 'sim-endpoint', 'name' => 'Endpoint Filtering', 'enabled' => true, 'allow' => ['x.example.org'], 'block' => [], 'network_ids' => [], 'categories' => [], 'safe_search' => []];
        Cache::forever('mock.content_filters', $filters);
        $this->actingAs($this->admin)->post(route('admin.policy.setup.import-all'));
        $endpoint = FilterPolicy::query()->where('provider_reference', 'sim-endpoint')->sole();
        $leftover = NetworkProfile::query()->create(['key' => 'it_staff', 'name' => 'IT Staff', 'audience' => 'staff', 'probe_url' => 'http://probe-it-staff:3000', 'enabled' => false, 'sort_order' => 9]);

        $this->actingAs($this->admin)->post(route('admin.policy.setup.store'), ['filters' => ['sim-endpoint' => ['as' => 'skip']]])
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'no longer manages it'));
        $this->assertNull($endpoint->refresh()->provider_reference);

        $this->actingAs($this->admin)->get(route('admin.policy.setup.show'))->assertOk()->assertSee('Not used by any filter')->assertSee('Endpoint Filtering')->assertSee('IT Staff');
        $this->actingAs($this->admin)->post(route('admin.policy.setup.cleanup'), ['policies' => [$endpoint->id], 'profiles' => [$leftover->id]])->assertSessionHas('success');
        $this->assertModelMissing($endpoint);
        $this->assertModelMissing($leftover);
        $this->assertNotNull(NetworkProfile::query()->where('key', 'student')->first(), 'Built-in profiles are never offered');
    }

    public function test_a_policy_with_entries_added_by_hand_is_not_offered_for_clean_up(): void
    {
        $policy = FilterPolicy::query()->create(['name' => 'Old', 'slug' => 'old', 'audience' => 'staff']);
        $this->engine->upsertEntry($this->engine->ownList($policy, ListKind::Allow), 'kept.example.org', ['source' => EntrySource::Manual]);

        $this->actingAs($this->admin)->get(route('admin.policy.setup.show'))->assertOk()->assertDontSee('value="'.$policy->id.'" checked', false);
    }

    public function test_entries_run_together_are_flagged_and_split(): void
    {
        $this->assertSame(['wistia.com', 'fast.wistia.net'], RunTogetherEntries::split('wistia.comfast.wistia.net'));
        $this->assertNull(RunTogetherEntries::split('mail.comcast.net'));
        $this->assertNull(RunTogetherEntries::split('www.example.com'));
        $this->assertNull(RunTogetherEntries::split('news.com.au'));

        $this->schoolTimeFirst();
        $this->actingAs($this->admin)->post(route('admin.policy.networks.link'), ['filter_id' => 'sim-all-hours', 'managed_by' => 'new:student'])->assertSessionHas('success');
        $policy = FilterPolicy::query()->where('provider_reference', 'sim-all-hours')->sole();
        $this->engine->upsertEntry($this->engine->ownList($policy, ListKind::Allow), 'wistia.comfast.wistia.net', ['source' => EntrySource::Import->value]);

        $this->actingAs($this->admin)->get(route('admin.policy.policies.show', $policy))->assertOk()
            ->assertSee('like two hostnames run together')->assertSee('Split into wistia.com and fast.wistia.net');
        $entry = PolicyEntry::query()->where('domain', 'wistia.comfast.wistia.net')->firstOrFail();
        $this->actingAs($this->admin)->post(route('admin.policy.policies.entries.split', [$policy, $entry]))->assertSessionHas('success');

        $desired = $this->engine->desired($policy->refresh());
        $this->assertContains('wistia.com', $desired['allow']);
        $this->assertContains('fast.wistia.net', $desired['allow']);
        $this->assertNotContains('wistia.comfast.wistia.net', $desired['allow']);
        $this->assertContains('wistia.comfast.wistia.net', $this->engine->reconcile($policy)['allow_remove'], 'Taken out of UniFi at the next publish');
    }
}
