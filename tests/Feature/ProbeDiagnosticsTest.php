<?php

namespace Tests\Feature;

use App\Models\NetworkAssignment;
use App\Models\NetworkProfile;
use App\Models\User;
use App\Services\Policy\ControllerSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

class ProbeDiagnosticsTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::flush();
        $this->installWithPolicies('active');
        app(ControllerSync::class)->state(true);
        NetworkAssignment::query()->where('provider_network_id', 'sim-guest')->update(['dhcp_start' => '10.0.80.6', 'dhcp_stop' => '10.0.80.254']);
        NetworkProfile::query()->where('key', 'student')->update(['probe_url' => 'http://probe-student:3000', 'unifi_network_id' => 'sim-student', 'enabled' => true]);
        NetworkProfile::query()->where('key', 'staff')->update(['probe_url' => 'http://probe-staff:3000', 'unifi_network_id' => 'sim-guest', 'enabled' => false]);
    }

    /** @return array<string, mixed> */
    private function report(string $gateway, string $address, array $overrides = []): array
    {
        return $overrides + [
            'hostname' => 'x', 'gateway' => $gateway, 'dns' => [$gateway], 'dns_mode' => 'direct',
            'interfaces' => [['name' => 'eth1', 'mac' => '02:aa:bb:cc:dd:ee', 'ipv4' => [$address], 'default_route' => true]],
            'gateway_arp' => true, 'gateway_tcp' => true, 'dns_ok' => true, 'internet_ok' => true,
        ];
    }

    public function test_each_probe_gets_a_checklist_with_what_to_fix(): void
    {
        Http::fake([
            // On its VLAN, but the gateway never answers: a trunk or hypervisor problem.
            'probe-student:3000/diagnose' => Http::response($this->report('10.0.20.1', '10.0.20.241/24', ['gateway_arp' => false, 'gateway_tcp' => false, 'dns_ok' => false, 'dns_error' => 'ETIMEOUT', 'internet_ok' => false])),
            // Working, address inside DHCP, network unfiltered, switched off.
            'probe-staff:3000/diagnose' => Http::response($this->report('10.0.80.1', '10.0.80.241/24')),
        ]);

        $page = $this->actingAs(User::factory()->admin()->create())->get(route('admin.settings.show', 'profiles'))->assertOk();
        $page->assertSee('Setup checklist for Student Access')->assertSee('Gateway reachable')
            ->assertSee('carries VLAN 20 tagged', false)->assertSee('sudo bin/probe-interfaces --listen eth0', false)
            ->assertSee('turn off the firewall', false)
            ->assertSee('inside Guest Wi-Fi&#039;s DHCP range', false)->assertSee('end the DHCP range below 10.0.80.241', false)
            ->assertSee('No policy covers Guest Wi-Fi', false)
            ->assertSee('Tick On for this profile and save.');
        Http::assertSent(fn ($request) => $request->hasHeader('X-Probe-Key') && str_ends_with($request->url(), '/diagnose'));

        $this->actingAs(User::factory()->admin()->create())->get(route('dashboard'))->assertOk()
            ->assertSee('Probes need finishing')->assertSee('Student Access');
    }

    public function test_a_probe_on_dockers_own_network_is_told_to_use_compose_vlan(): void
    {
        Http::fake([
            'probe-student:3000/diagnose' => Http::response($this->report('172.24.0.1', '172.24.0.2/16', ['dns' => ['127.0.0.11']])),
            'probe-staff:3000/diagnose' => Http::response([], 500),
        ]);

        $this->artisan('probes:check')->assertSuccessful()
            ->expectsOutputToContain("not Student Access's gateway 10.0.20.1")
            ->expectsOutputToContain('-f compose.vlan.yml')
            ->expectsOutputToContain('Check PROBE_SHARED_KEY');
    }
}
