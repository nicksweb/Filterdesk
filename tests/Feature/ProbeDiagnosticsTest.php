<?php

namespace Tests\Feature;

use App\Models\NetworkAssignment;
use App\Models\NetworkProfile;
use App\Models\User;
use App\Services\Network\MockNetworkProvider;
use App\Services\Policy\ControllerSync;
use App\Services\Policy\ProbePlanner;
use App\Services\Website\ProbeDiagnostics;
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
            'gateway_arp' => true, 'gateway_tcp' => true, 'dns_ok' => true, 'internet_ok' => true, 'browser_dns' => 'pinned',
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
            ->assertSee('carries VLAN 20 tagged', false)->assertSee('sudo bin/probe-interfaces --listen &lt;trunk&gt;', false)
            ->assertSee('turn off the firewall', false)
            ->assertSee('inside Guest Wi-Fi&#039;s DHCP range', false)->assertSee('end the DHCP range below 10.0.80.241', false)->assertSee('Reserve 10.0.80.241 in UniFi')
            ->assertSee('No policy covers Guest Wi-Fi', false)
            ->assertSee('Tick On for this profile and save.');
        Http::assertSent(fn ($request) => $request->hasHeader('X-Probe-Key') && str_ends_with($request->url(), '/diagnose'));

        $this->actingAs(User::factory()->admin()->create())->get(route('dashboard'))->assertOk()
            ->assertSee('Probes need finishing')->assertSee('Student Access');
    }

    public function test_a_host_the_gateway_intercepts_is_not_reported_as_no_internet(): void
    {
        Http::fake([
            'probe-student:3000/diagnose' => Http::response($this->report('10.0.20.1', '10.0.20.241/24', ['intercepted' => [['host' => 'www.google.com', 'issuer' => 'UniFi SSL Certificate Authority, Ubiquiti Inc.', 'error' => 'SELF_SIGNED_CERT_IN_CHAIN']]])),
            'probe-staff:3000/diagnose' => Http::response($this->report('10.0.80.1', '10.0.80.241/24')),
        ]);

        $this->actingAs(User::factory()->admin()->create())->get(route('admin.settings.show', 'profiles'))->assertOk()
            ->assertSee('Internet works, but HTTPS to www.google.com is intercepted by the gateway (certificate from UniFi SSL Certificate Authority, Ubiquiti Inc.)', false)
            ->assertDontSee('It cannot load a web page');
    }

    public function test_a_host_inspected_with_the_schools_uploaded_certificate_is_expected_and_other_sites_decide(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'UniFi SSL Certificate Authority', 'organizationName' => 'Ubiquiti Inc.'], $key);
        openssl_x509_export(openssl_csr_sign($csr, null, $key, 365), $pem);
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->put(route('admin.settings.update', 'certificate'), ['certificate_text' => $pem])->assertSessionHasNoErrors();

        Http::fake([
            // Google is inspected, so the probe's own internet test failed, but Cloudflare loaded.
            'probe-student:3000/diagnose' => Http::response($this->report('10.0.20.1', '10.0.20.241/24', [
                'internet_ok' => false,
                'internet_results' => [['host' => 'www.google.com', 'ok' => false], ['host' => 'cp.cloudflare.com', 'ok' => true]],
                'intercepted' => [['host' => 'www.google.com', 'issuer' => 'UniFi SSL Certificate Authority, Ubiquiti Inc.', 'error' => 'SELF_SIGNED_CERT_IN_CHAIN']],
            ])),
            'probe-staff:3000/diagnose' => Http::response($this->report('10.0.80.1', '10.0.80.241/24')),
        ]);

        $profile = NetworkProfile::query()->where('key', 'student')->sole();
        $internet = collect(app(ProbeDiagnostics::class)->for($profile, refresh: true)['checks'])->firstWhere('key', 'internet');
        $this->assertSame('ok', $internet['status']);
        $this->assertStringContainsString("inspected with your school's certificate", $internet['detail']);
        $this->assertStringContainsString('cp.cloudflare.com', $internet['detail']);
    }

    public function test_a_probe_on_dockers_own_network_is_told_to_use_compose_vlan(): void
    {
        Http::fake([
            'probe-student:3000/diagnose' => Http::response($this->report('172.24.0.1', '172.24.0.2/16', ['dns' => ['127.0.0.11']])),
            'probe-staff:3000/diagnose' => Http::response([], 500),
        ]);

        $this->artisan('probes:check')->assertSuccessful()
            ->expectsOutputToContain("not Student Access's gateway 10.0.20.1")
            ->expectsOutputToContain('COMPOSE_FILE line that includes compose.vlan.yml')
            ->expectsOutputToContain('Check PROBE_SHARED_KEY');
    }

    public function test_an_address_inside_dhcp_can_be_reserved_moved_outside_the_pool_or_marked_reviewed(): void
    {
        Http::fake(['probe-staff:3000/diagnose' => Http::response($this->report('10.0.80.1', '10.0.80.241/24')), 'probe-student:3000/diagnose' => Http::response([], 500)]);
        $admin = User::factory()->admin()->create();
        $staff = NetworkProfile::query()->where('key', 'staff')->sole();

        $this->actingAs($admin)->get(route('admin.settings.show', 'profiles'))->assertOk()
            ->assertSee('Reserve 10.0.80.241 in UniFi')->assertSee('Use 10.0.80.5 instead')->assertSee('data-remember="probe-'.$staff->id.'-', false);

        $this->actingAs($admin)->post(route('admin.probes.reserve', $staff))->assertSessionHas('success');
        $this->assertSame('10.0.80.241', (new MockNetworkProvider)->reservations()['02:aa:bb:cc:dd:ee']['ip']);
        $dhcp = collect(app(ProbeDiagnostics::class)->for($staff)['checks'])->firstWhere('key', 'dhcp');
        $this->assertSame('ok', $dhcp['status']);
        $this->assertStringContainsString('reserved for 02:aa:bb:cc:dd:ee', $dhcp['detail']);

        $this->actingAs($admin)->post(route('admin.probes.outside-pool', $staff))->assertSessionHas('success', fn ($message) => str_contains($message, '10.0.80.5'));
        $this->assertSame('10.0.80.5', app(ProbePlanner::class)->saved()['addresses']['sim-guest']);
        $this->assertStringContainsString('--ip-range=10.0.80.5/32', app(ProbePlanner::class)->composeFile(app(ProbePlanner::class)->plan()));

        $filtered = collect(app(ProbeDiagnostics::class)->for($staff)['checks'])->firstWhere('key', 'filtered');
        $this->assertSame('warn', $filtered['status']);
        $this->actingAs($admin)->post(route('admin.probes.review', $staff), ['check' => 'filtered', 'reviewed' => 1])->assertSessionHas('success');
        $result = app(ProbeDiagnostics::class)->for($staff);
        $this->assertSame('reviewed', collect($result['checks'])->firstWhere('key', 'filtered')['status']);
        $this->assertNotContains('warn', collect($result['checks'])->pluck('status')->all());

        $this->actingAs($admin)->post(route('admin.probes.review', $staff), ['check' => 'running', 'reviewed' => 1])->assertSessionHas('error');
    }

    public function test_a_relayed_network_is_told_to_reserve_on_its_own_dhcp_server(): void
    {
        NetworkAssignment::query()->where('provider_network_id', 'sim-guest')->update(['dhcp_mode' => 'relay', 'dhcp_start' => null, 'dhcp_stop' => null]);
        Http::fake(['probe-staff:3000/diagnose' => Http::response($this->report('10.0.80.1', '10.0.80.241/24')), 'probe-student:3000/diagnose' => Http::response([], 500)]);

        $dhcp = collect(app(ProbeDiagnostics::class)->for(NetworkProfile::query()->where('key', 'staff')->sole())['checks'])->firstWhere('key', 'dhcp');
        $this->assertSame('warn', $dhcp['status']);
        $this->assertStringContainsString('uses a DHCP relay', $dhcp['detail']);
        $this->assertStringContainsString('reserve 10.0.80.241 for MAC 02:aa:bb:cc:dd:ee', $dhcp['fix'][0]);
    }
}
