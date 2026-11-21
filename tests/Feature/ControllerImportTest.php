<?php

namespace Tests\Feature;

use App\Models\FilterPolicy;
use App\Models\IntegrationConfig;
use App\Models\NetworkAssignment;
use App\Models\NetworkProfile;
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

class ControllerImportTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    private PolicyEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::flush();
        $this->engine = $this->installWithPolicies('active');

        $filters = (new MockNetworkProvider)->contentFilters();
        $filters[1]['allow'] = ['portal.example.edu'];
        $filters[1]['block'] = ['games.example.com'];
        $filters[] = ['id' => 'sim-filter-staff-feeds', 'name' => 'Staff - Blocklists', 'enabled' => true, 'allow' => [],
            'block' => array_map(fn (int $index) => "tracker{$index}.example.net", range(1, 1500)), 'network_ids' => ['sim-staff'], 'categories' => ['ADULT'], 'safe_search' => []];
        Cache::forever('mock.content_filters', $filters);
    }

    public function test_bringing_everything_in_reuses_the_default_policies_recognises_the_layer_and_changes_nothing_on_the_first_push(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('admin.policy.setup.show'))->assertOk()
            ->assertSee('Bring everything in')->assertSee('Looks like a blocklist layer of Staff', false);

        $this->actingAs($admin)->post(route('admin.policy.setup.import-all'))->assertRedirect()->assertSessionHas('success');

        $students = FilterPolicy::query()->where('audience', 'student')->sole();
        $staff = FilterPolicy::query()->main()->where('audience', 'staff')->sole();
        $this->assertSame('sim-filter-students', $students->provider_reference, 'The default Students policy is reused, not duplicated');
        $this->assertSame('sim-filter-staff', $staff->provider_reference);
        $this->assertSame(3, FilterPolicy::query()->count());

        $layer = $staff->layer;
        $this->assertSame('sim-filter-staff-feeds', $layer?->provider_reference);
        $this->assertSame($staff->id, NetworkAssignment::query()->where('provider_network_id', 'sim-staff')->value('filter_policy_id'), 'The network stays mapped to the parent');
        $this->assertCount(1500, $this->engine->desired($layer)['block']);
        $this->assertSame(['games.example.com'], $this->engine->desired($staff)['block']);
        $this->assertContains('portal.example.edu', $this->engine->desired($staff)['allow']);

        foreach ([$students, $staff, $layer] as $policy) {
            $report = $this->engine->reconcile($policy->refresh());
            $this->assertSame([[], [], [], []], [$report['allow_add'], $report['allow_remove'], $report['block_add'], $report['block_remove']], "{$policy->name} would change on the first push");
        }
        $this->assertTrue(NetworkProfile::query()->where('key', 'staff')->value('unifi_network_id') === 'sim-staff');

        $this->actingAs($admin)->get(route('admin.policy.setup.show'))->assertOk()->assertSee('Ready to go')->assertSee('layer of Staff', false);
        $this->actingAs($admin)->post(route('admin.policy.setup.import-all'))->assertSessionHas('success');
        $this->assertSame(3, FilterPolicy::query()->count(), 'Running it again changes nothing');
    }

    public function test_compose_file_covers_every_policy_and_extra_networks(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('admin.policy.setup.import-all'));
        app(ControllerSync::class)->state(true);

        $file = $this->actingAs($admin)->get(route('admin.policy.setup.compose', ['parent' => 'eno1', 'extra' => ['sim-guest']]))
            ->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="compose.vlan.yml"')->getContent();

        $this->assertStringContainsString("services:\n  probe-student:", $file);
        $this->assertStringContainsString('probe-staff:', $file);
        $this->assertStringContainsString('probe-net-guest-wi-fi-80:', $file);
        $this->assertStringContainsString('PROBE_DNS_SERVERS: 10.0.80.1', $file);
        $this->assertStringContainsString('-o parent=eno1.80 itr_net_guest_wi_fi_80_vlan', $file);
        $this->assertStringContainsString('name: itr_net_guest_wi_fi_80_vlan', $file);
        $this->assertStringContainsString("ipv4_address: 10.0.80.241\n        mac_address: 02:", $file, 'Fixed address in the reserved range and a locally administered MAC');
        $this->assertStringNotContainsString('Blocklists', $file, 'Layers share their parent probe');

        $this->artisan('probes:compose', ['--parent' => 'eno1', '--network' => ['sim-guest']])->assertSuccessful()->expectsOutputToContain('probe-net-guest-wi-fi-80:');

        $this->actingAs($admin)->post(route('admin.policy.setup.profiles'), ['parent' => 'eno1', 'extra' => ['sim-guest']])->assertSessionHas('success');
        $guest = NetworkProfile::query()->where('key', 'net_guest_wi_fi_80')->sole();
        $this->assertFalse($guest->enabled);
        $this->assertSame('http://probe-net-guest-wi-fi-80:3000', $guest->probe_url);
        $this->assertSame('other', $guest->audience, 'An unmapped network has no type of its own');
    }

    public function test_saved_trunk_tags_each_probe_and_an_override_or_extra_network_is_kept(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('admin.policy.setup.import-all'));

        $this->actingAs($admin)->post(route('admin.policy.setup.probes'), ['trunk' => 'eno1; rm -rf /'])->assertSessionHasErrors('trunk');
        $this->actingAs($admin)->post(route('admin.policy.setup.probes'), [
            'trunk' => 'eno1', 'interfaces' => ['sim-student' => 'eno2', 'sim-staff' => ''], 'extra' => ['sim-guest'],
        ])->assertSessionHas('success');

        $file = $this->actingAs($admin)->get(route('admin.policy.setup.compose'))->assertOk()->getContent();
        $this->assertStringContainsString('-o parent=eno1.10 itr_staff_vlan', $file, 'Tagged VLAN on the trunk');
        $this->assertStringContainsString('-o parent=eno2 itr_student_vlan', $file, 'Per-probe override');
        $this->assertStringContainsString('-o parent=eno1.80 itr_net_guest_wi_fi_80_vlan', $file, 'Saved extra network');
        $this->assertStringContainsString('# Host interfaces: probe-student → eno2, probe-staff → eno1.10, probe-net-guest-wi-fi-80 → eno1.80', $file);

        $this->artisan('probes:compose')->assertSuccessful()->expectsOutputToContain('-o parent=eno1.80');
        $this->actingAs($admin)->get(route('admin.policy.setup.show'))->assertOk()->assertSee('value="eno1"', false)->assertSee('set by hand');
    }

    public function test_vlan_numbers_typed_in_the_installer_place_each_probe_on_that_vlan(): void
    {
        NetworkProfile::query()->where('key', 'student')->update(['unifi_network_id' => '80']);
        NetworkProfile::query()->where('key', 'staff')->update(['unifi_network_id' => '10']);

        app(ControllerSync::class)->state(true);

        $this->assertSame('sim-guest', NetworkProfile::query()->where('key', 'student')->value('unifi_network_id'));
        $this->assertNull(NetworkAssignment::query()->where('provider_network_id', 'sim-guest')->value('filter_policy_id'), 'A probe VLAN does not change which policy filters that network');

        $file = $this->actingAs(User::factory()->admin()->create())->get(route('admin.policy.setup.compose', ['parent' => 'eno1']))->getContent();
        $this->assertStringContainsString('-o parent=eno1.80 itr_student_vlan', $file);
        $this->assertStringContainsString('-o parent=eno1.10 itr_staff_vlan', $file);
        $services = collect(app(ProbePlanner::class)->plan('eno1'))->pluck('service');
        $this->assertSame($services->unique()->values()->all(), $services->values()->all(), 'Every probe has its own service name');
        $this->actingAs(User::factory()->admin()->create())->get(route('admin.policy.setup.show'))
            ->assertSee('10.0.80.241 is inside Guest Wi-Fi&#039;s DHCP range (10.0.80.6–10.0.80.254)', false)
            ->assertSee('or use 10.0.80.5, which is outside the pool', false)
            ->assertSee('no content filter in UniFi covers Guest Wi-Fi', false);
    }

    public function test_the_dashboard_points_a_new_install_at_guided_setup(): void
    {
        IntegrationConfig::query()->where('provider', 'mock')->update(['provider' => 'unifi', 'configuration' => ['controller_url' => 'https://unifi.example.test'], 'credentials' => ['api_key' => 'test']]);
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertSee('Next: bring your UniFi setup in.');

        FilterPolicy::query()->first()->update(['provider_reference' => 'linked']);
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertDontSee('Next: bring your UniFi setup in.');
    }
}
