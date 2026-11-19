<?php

namespace Tests\Feature;

use App\Enums\Audience;
use App\Enums\ListScope;
use App\Models\FilterPolicy;
use App\Models\IntegrationConfig;
use App\Models\NetworkAssignment;
use App\Models\NetworkProfile;
use App\Models\PolicyList;
use App\Models\User;
use App\Services\Network\MockNetworkProvider;
use App\Services\Policy\ControllerSync;
use App\Services\Policy\PolicyEngine;
use App\Services\Policy\ProbePlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

class ControllerSetupTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    private PolicyEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::flush();
        $this->engine = $this->installWithPolicies('active');
    }

    public function test_guided_setup_creates_policies_from_controller_filters_with_their_own_lists_and_networks(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = FilterPolicy::query()->where('audience', Audience::Staff)->firstOrFail();

        $this->actingAs($admin)->get(route('admin.policy.setup.show'))->assertOk()->assertSee('Students')->assertSee('VLAN 20', false);

        $this->actingAs($admin)->post(route('admin.policy.setup.store'), ['filters' => [
            'sim-filter-students' => ['action' => 'create', 'audience' => 'student', 'import' => '1'],
            'sim-filter-staff' => ['action' => 'link', 'policy_id' => $staff->id, 'import' => '0'],
        ]])->assertRedirect()->assertSessionHas('success');

        $created = FilterPolicy::query()->where('provider_reference', 'sim-filter-students')->firstOrFail();
        $this->assertSame(Audience::Student, $created->audience);
        $this->assertSame($created->id, NetworkAssignment::query()->where('provider_network_id', 'sim-student')->value('filter_policy_id'));
        $this->assertSame('sim-filter-staff', $staff->refresh()->provider_reference);

        $ownList = PolicyList::query()->where('scope', ListScope::Policy)->where('filter_policy_id', $created->id)->firstOrFail();
        $this->assertSame(['example.edu'], $ownList->entries()->pluck('domain')->all());
        $this->assertSame('in_sync', $created->refresh()->sync_status, 'Importing keeps the controller unchanged on the first push');
        $this->assertNotContains('example.edu', $this->engine->desired($staff)['allow'], 'Imported entries stay with their own policy');
    }

    public function test_probe_plan_puts_each_policy_on_its_vlan_with_its_gateway_for_dns(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('admin.policy.setup.store'), ['filters' => [
            'sim-filter-students' => ['action' => 'link', 'policy_id' => FilterPolicy::query()->where('audience', Audience::Student)->value('id')],
            'sim-filter-staff' => ['action' => 'link', 'policy_id' => FilterPolicy::query()->where('audience', Audience::Staff)->value('id')],
        ]]);

        $this->actingAs($admin)->get(route('admin.policy.setup.show', ['parent' => 'eno1']))->assertOk()
            ->assertSee('--subnet=10.0.20.0/24 --gateway=10.0.20.1', false)
            ->assertSee('-o parent=eno1.20', false)
            ->assertSee('STUDENT_PROBE_DNS_SERVERS=10.0.20.1', false)
            ->assertSee('STAFF_PROBE_DNS_SERVERS=10.0.10.1', false);

        $this->actingAs($admin)->post(route('admin.policy.setup.profiles'))->assertSessionHas('success');
        $this->assertSame('sim-student', NetworkProfile::query()->where('key', 'student')->value('unifi_network_id'));
    }

    public function test_subnet_maths_suggests_a_small_range_at_the_top_of_the_network(): void
    {
        $planner = app(ProbePlanner::class);

        $this->assertSame(['gateway' => '10.20.0.1', 'network' => '10.20.0.0/22', 'probe_range' => '10.20.3.240/29', 'prefix' => 22], $planner->subnet('10.20.0.1/22'));
        $this->assertSame('10.99.1.232/29', $planner->subnet('10.99.1.241/23')['probe_range'], 'The range never contains the gateway');
        $this->assertNull($planner->subnet('fd00::1/64'));
        $this->assertNull($planner->subnet(null));
    }

    public function test_a_network_in_two_content_filters_is_flagged(): void
    {
        $filters = (new MockNetworkProvider)->contentFilters();
        $filters[1]['network_ids'][] = 'sim-student';
        Cache::forever('mock.content_filters', $filters);

        $student = FilterPolicy::query()->where('audience', Audience::Student)->firstOrFail();
        $student->update(['provider_reference' => 'sim-filter-students']);
        app(ControllerSync::class)->state(true);
        NetworkAssignment::query()->where('provider_network_id', 'sim-student')->update(['filter_policy_id' => $student->id]);

        $this->actingAs(User::factory()->it()->create())->get(route('admin.policy.networks.index'))->assertOk()
            ->assertSee('In 2 filters', false)->assertSee('can still be blocked by Staff', false);

        $this->artisan('policy:check-sync')->assertSuccessful();
        $this->assertSame('drift', $student->refresh()->sync_status);
        $this->assertStringContainsString('also in Staff', $student->sync_details['summary']);
    }

    public function test_categories_are_saved_to_the_linked_filter_and_edits_in_the_controller_are_detected(): void
    {
        IntegrationConfig::query()->where('provider', 'mock')->update(['configuration' => ['controller_authoritative' => false]]);
        $admin = User::factory()->admin()->create();
        $student = FilterPolicy::query()->where('audience', Audience::Student)->firstOrFail();
        $student->update(['provider_reference' => 'sim-filter-students']);

        $this->actingAs($admin)->get(route('admin.policy.policies.show', $student))->assertOk()->assertSee('Content categories')->assertSee('Social networks');

        $this->actingAs($admin)->post(route('admin.policy.policies.categories', $student), ['categories' => ['ADULT', 'GAMBLING', 'NOT_A_CATEGORY']])
            ->assertSessionHas('success');
        $filter = collect((new MockNetworkProvider)->contentFilters())->firstWhere('id', 'sim-filter-students');
        $this->assertSame(['ADULT', 'GAMBLING'], $filter['categories']);
        $this->assertSame('in_sync', $student->refresh()->sync_status);

        (new MockNetworkProvider)->writeContentFilterCategories('sim-filter-students', ['ADULT']);
        $this->artisan('policy:check-sync')->assertSuccessful();
        $this->assertSame(['GAMBLING'], $student->refresh()->sync_details['changed_in_controller']['categories_removed']);

        $this->actingAs(User::factory()->it()->create())->post(route('admin.policy.policies.categories', $student), ['categories' => []])->assertForbidden();
    }
}
