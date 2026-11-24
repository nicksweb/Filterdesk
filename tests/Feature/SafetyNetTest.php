<?php

namespace Tests\Feature;

use App\Models\FilterPolicy;
use App\Models\IntegrationConfig;
use App\Models\NetworkAssignment;
use App\Models\PolicyEntry;
use App\Models\User;
use App\Services\Network\MockNetworkProvider;
use App\Services\Policy\ControllerLearner;
use App\Services\Policy\ControllerPusher;
use App\Services\Policy\ControllerSync;
use App\Services\Policy\PolicyEngine;
use App\Services\Policy\ProtectedSites;
use App\Services\Policy\SyncChecker;
use App\Services\Requests\RequestWorkflow;
use App\Support\Reach;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

/**
 * The safety nets: protected sites are never blocked; a filter link is never
 * moved without asking, keeps the filter's entries and holds automatic
 * pushes until a person checks the plan; an unreviewed push never empties a
 * filter; network mappings follow UniFi; and the filtering map and category
 * copy that make the whole chain visible and consistent.
 */
class SafetyNetTest extends TestCase
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

    private function filter(string $id): array
    {
        return collect((new MockNetworkProvider)->contentFilters())->firstWhere('id', $id);
    }

    private function linkStudents(): FilterPolicy
    {
        $students = FilterPolicy::query()->where('audience', 'student')->firstOrFail();
        $this->actingAs($this->admin)->post(route('admin.policy.networks.link'), ['filter_id' => 'sim-filter-students', 'managed_by' => 'policy:'.$students->id])->assertSessionHas('success');

        return $students->refresh();
    }

    public function test_protected_top_levels_are_never_blocked_but_their_subdomains_can_be(): void
    {
        $sites = app(ProtectedSites::class);
        $this->assertTrue($sites->protects('google.com'));
        $this->assertTrue($sites->protects('www.google.com'));
        $this->assertFalse($sites->protects('gemini.google.com'), 'A subdomain of a top-level entry can still be blocked');
        $this->assertTrue($sites->protects('fonts.gstatic.com'), '*. protects the whole domain');
        $this->assertTrue($sites->protects('com.au'), 'A public suffix on its own');
        $this->assertFalse($sites->protects('game.example.com'));

        $students = FilterPolicy::query()->where('audience', 'student')->firstOrFail();
        $list = $this->engine->defaultBlockList('student');
        foreach (['google.com', 'gemini.google.com', 'fonts.gstatic.com'] as $domain) {
            $this->engine->upsertEntry($list, $domain, ['source' => 'feed']);
        }
        $desired = $this->engine->desired($students);
        $this->assertSame(['gemini.google.com'], $desired['block']);
        $this->assertSame(['fonts.gstatic.com', 'google.com'], array_keys($desired['protected']));
    }

    public function test_adding_a_protected_site_to_a_block_list_is_refused_and_the_rest_go_in(): void
    {
        $list = $this->engine->defaultBlockList('student');
        $this->actingAs($this->admin)->post(route('admin.policy.entries.store'), ['policy_list_id' => $list->id, 'domains' => 'bing.com'])
            ->assertSessionHasErrors(['domains' => ProtectedSites::message(['bing.com' => 'bing.com'])]);

        $this->actingAs($this->admin)->post(route('admin.policy.entries.store'), ['policy_list_id' => $list->id, 'hosts' => ['game.example.com', 'fonts.googleapis.com']])
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'fonts.googleapis.com (*.googleapis.com)'));
        $this->assertSame(['game.example.com'], $list->entries()->pluck('domain')->all());

        // Allow lists are not affected.
        $this->actingAs($this->admin)->post(route('admin.policy.entries.store'), ['policy_list_id' => $this->engine->defaultAllowList('student')->id, 'domains' => 'bing.com'])->assertSessionHas('success');
    }

    public function test_a_block_request_skips_protected_sites_and_quick_block_refuses_them(): void
    {
        $request = $this->pendingRequest(['microsoft.com', 'game.example.com'], Reach::types('student'), ['request_type' => 'block']);
        $approved = app(RequestWorkflow::class)->approve($request, User::factory()->it()->create());
        $this->assertSame(['game.example.com'], $approved->approved_domains);
        $this->assertFalse(PolicyEntry::query()->where('domain', 'microsoft.com')->exists());

        $this->actingAs($this->admin)->post(route('admin.policy.quick.preview'), ['domains' => 'apple.com', 'kind' => 'block', 'types' => ['student'], 'when' => 'all'])
            ->assertSessionHasErrors('domains');
    }

    public function test_a_protected_site_blocked_in_unifi_is_taken_out_and_the_list_is_editable(): void
    {
        $students = $this->linkStudents();
        $students->update(['current_blocklist' => ['google.com']]);
        $this->assertContains('google.com', $this->engine->reconcile($students)['block_remove']);

        $this->actingAs($this->admin)->put(route('admin.settings.update', 'protected'), ['sites' => "https://Example.org/path\n*.cdn.example.net\nnot a host"])->assertSessionHas('success');
        $this->assertSame(['example.org', '*.cdn.example.net'], app(ProtectedSites::class)->all());
        $this->actingAs($this->admin)->get(route('admin.settings.show', 'protected'))->assertOk()->assertSee('*.cdn.example.net');
        $this->actingAs(User::factory()->it()->create())->put(route('admin.settings.update', 'protected'), ['sites' => ''])->assertForbidden();
    }

    public function test_moving_a_linked_policy_asks_first_and_a_new_link_keeps_the_filters_entries(): void
    {
        $students = $this->linkStudents();
        $this->assertContains('example.edu', $this->engine->desired($students)['allow'], 'The filter\'s entries were imported on link');
        $this->assertNotNull($students->push_hold);

        // Staff filter: choosing the already-linked Students policy only asks.
        $this->actingAs($this->admin)->post(route('admin.policy.networks.link'), ['filter_id' => 'sim-filter-staff', 'managed_by' => 'policy:'.$students->id])
            ->assertSessionHas('link_confirm', fn (array $confirm) => str_contains($confirm['moves'][0], 'Students stops managing Students'));
        $this->assertSame('sim-filter-students', $students->refresh()->provider_reference);
        $this->actingAs($this->admin)->get(route('admin.policy.networks.index'))->assertOk();
    }

    public function test_a_held_policy_is_not_pushed_automatically_until_a_person_pushes_it(): void
    {
        IntegrationConfig::query()->where('provider', 'mock')->update(['configuration' => ['auto_push' => true]]);
        $students = $this->linkStudents();
        $this->engine->upsertEntry($this->engine->defaultAllowList('student'), 'lesson.example.org', ['source' => 'manual']);

        $results = app(ControllerPusher::class)->pushAll(null, 'schedule');
        $this->assertFalse($results->firstWhere('policy.id', $students->id)['ok']);
        $this->assertNotContains('lesson.example.org', $this->filter('sim-filter-students')['allow']);
        $this->actingAs($this->admin)->get(route('admin.policy.policies.show', $students))->assertOk()->assertSee('On hold: nothing is pushed');

        $this->actingAs($this->admin)->post(route('admin.policy.policies.push', $students));
        $this->assertContains('lesson.example.org', $this->filter('sim-filter-students')['allow']);
        $this->assertNull($students->refresh()->push_hold);
    }

    public function test_an_unreviewed_push_that_would_empty_a_filter_is_refused(): void
    {
        $filters = (new MockNetworkProvider)->contentFilters();
        $filters[1]['allow'] = ['a.example.org', 'b.example.org', 'c.example.org', 'd.example.org'];
        Cache::forever('mock.content_filters', $filters);
        $staff = FilterPolicy::query()->where('audience', 'staff')->firstOrFail();
        $staff->update(['provider_reference' => 'sim-filter-staff', 'provider_name' => 'Staff', 'controller_managed' => ['allow' => $filters[1]['allow'], 'block' => []]]);

        $result = app(ControllerPusher::class)->push($staff, null, 'schedule');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('would remove 4 of 4 entries', $result['message']);
        $this->assertCount(4, $this->filter('sim-filter-staff')['allow']);
    }

    public function test_mappings_follow_unifi_and_device_networks_with_site_wide_filtering_get_no_students_advice(): void
    {
        Cache::forever('mock.networks', [
            ['id' => 'sim-staff', 'name' => 'Staff Access', 'vlan' => 10, 'purpose' => 'corporate', 'subnet' => '10.0.10.1/24'],
            ['id' => 'sim-student', 'name' => 'Student Access', 'vlan' => 20, 'purpose' => 'corporate', 'subnet' => '10.0.20.1/24'],
            ['id' => 'sim-cctv', 'name' => 'CCTV', 'vlan' => 50, 'purpose' => 'corporate', 'subnet' => '10.0.50.1/24'],
        ]);
        $filters = (new MockNetworkProvider)->contentFilters();
        $filters[] = ['id' => 'sim-block-site', 'name' => 'Block (Sitewide)', 'enabled' => true, 'allow' => [], 'block' => ['bad.example.net'], 'network_ids' => ['sim-staff', 'sim-student', 'sim-cctv'], 'categories' => ['ADULT'], 'safe_search' => []];
        Cache::forever('mock.content_filters', $filters);
        $this->actingAs($this->admin)->post(route('admin.policy.setup.import-all'));

        $learned = app(ControllerLearner::class)->learn(app(ControllerSync::class)->state(true));
        $this->assertNull($learned['networks']['sim-cctv']['advice'], 'Site-wide filtering on a device network is as intended');

        // Student Access mapped to Staff here, but UniFi has the Students filter on it.
        $staff = FilterPolicy::query()->where('provider_reference', 'sim-filter-staff')->sole();
        NetworkAssignment::query()->where('provider_network_id', 'sim-student')->update(['filter_policy_id' => $staff->id]);
        $sync = app(ControllerSync::class);
        $changes = $sync->followController($sync->networkReport($sync->state(true)), $this->admin);
        $this->assertSame(['Student Access (VLAN 20): Staff → Students'], $changes);
    }

    public function test_the_filtering_map_shows_the_chain_for_a_network(): void
    {
        $this->actingAs($this->admin)->post(route('admin.policy.setup.import-all'));
        $this->actingAs(User::factory()->it()->create())->get(route('admin.policy.map', ['network' => 'sim-student']))->assertOk()
            ->assertSee('Student Access')->assertSee('This network\'s Students filter')->assertSee('Lists that fill it')->assertSee('Site-wide');
        $this->actingAs(User::factory()->teacher()->create())->get(route('admin.policy.map'))->assertForbidden();
    }

    public function test_categories_can_be_copied_from_another_filter_and_saved_to_several(): void
    {
        $this->actingAs($this->admin)->post(route('admin.policy.setup.import-all'));
        $students = FilterPolicy::query()->where('provider_reference', 'sim-filter-students')->sole();
        $staff = FilterPolicy::query()->where('provider_reference', 'sim-filter-staff')->sole();

        $this->actingAs($this->admin)->post(route('admin.policy.policies.categories', $staff), ['copy_from' => $students->id])->assertSessionHas('success');
        $this->assertSame(['ADULT'], $this->filter('sim-filter-staff')['categories']);

        $this->actingAs($this->admin)->post(route('admin.policy.policies.categories', $students), ['categories' => ['ADULT', 'GAMBLING'], 'also_to' => [$staff->id]])->assertSessionHas('success');
        $this->assertSame(['ADULT', 'GAMBLING'], $this->filter('sim-filter-students')['categories']);
        $this->assertSame(['ADULT', 'GAMBLING'], $this->filter('sim-filter-staff')['categories']);
        $this->actingAs($this->admin)->get(route('admin.policy.policies.show', $students))->assertOk()->assertSee('Also save this selection to')->assertSee('Copy the categories of another filter');
    }

    public function test_a_policy_whose_unifi_filter_was_deleted_is_listed_skipped_and_can_be_kept_or_deleted(): void
    {
        $this->actingAs($this->admin)->post(route('admin.policy.setup.import-all'));
        $staff = FilterPolicy::query()->where('provider_reference', 'sim-filter-staff')->sole();
        Cache::forever('mock.content_filters', collect((new MockNetworkProvider)->contentFilters())->reject(fn (array $filter) => $filter['id'] === 'sim-filter-staff')->values()->all());
        app(ControllerSync::class)->forget();
        app(SyncChecker::class)->checkAll(notify: false);
        $this->assertStringContainsString('was deleted. Delete this policy, keep it unlinked', $staff->refresh()->sync_details['summary']);

        $this->actingAs($this->admin)->get(route('admin.policy.index'))->assertOk()
            ->assertSee('Its UniFi filter Staff was deleted')->assertSee('Keep, unlinked')->assertSee('Delete policy');
        $this->actingAs($this->admin)->get('/admin/policy/policies/'.$staff->id)->assertOk();

        $results = app(ControllerPusher::class)->pushAll($this->admin);
        $skipped = $results->firstWhere('policy.id', $staff->id);
        $this->assertTrue($skipped['ok'] && $skipped['skipped']);

        $this->actingAs($this->admin)->post(route('admin.policy.policies.unlink', $staff))->assertSessionHas('success');
        $this->assertNull($staff->refresh()->provider_reference);
    }

    public function test_a_mapping_saved_from_an_out_of_date_page_is_refused_and_a_save_names_the_mapping(): void
    {
        $this->actingAs($this->admin)->post(route('admin.policy.setup.import-all'));
        $staff = FilterPolicy::query()->where('provider_reference', 'sim-filter-staff')->sole();
        $students = FilterPolicy::query()->where('provider_reference', 'sim-filter-students')->sole();

        $this->actingAs($this->admin)->put(route('admin.policy.networks.update'), ['assignments' => ['sim-guest' => $staff->id], 'was' => ['sim-guest' => '']])
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'Guest Wi-Fi (VLAN 80) → Staff'));

        // The page still shows "Not managed" for the row, and posts Students: refused, Staff stays.
        $this->actingAs($this->admin)->put(route('admin.policy.networks.update'), ['assignments' => ['sim-guest' => $students->id], 'was' => ['sim-guest' => '']])
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'Guest Wi-Fi (VLAN 80) is now Staff'));
        $this->assertSame($staff->id, NetworkAssignment::query()->where('provider_network_id', 'sim-guest')->value('filter_policy_id'));
    }
}
