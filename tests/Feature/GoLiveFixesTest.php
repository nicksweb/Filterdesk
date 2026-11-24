<?php

namespace Tests\Feature;

use App\Enums\EntrySource;
use App\Enums\ListKind;
use App\Models\FilterPolicy;
use App\Models\IntegrationConfig;
use App\Models\NetworkProfile;
use App\Models\PolicyEntry;
use App\Models\Setting;
use App\Models\User;
use App\Services\Domains\DomainCategorizer;
use App\Services\Network\MockNetworkProvider;
use App\Services\Policy\ControllerSync;
use App\Services\Policy\PolicyEngine;
use App\Services\Policy\SyncChecker;
use App\Services\Website\ProbeDiagnostics;
use App\Services\Website\ProbeSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

/**
 * What going live on a real console turned up: the probe checklist proves
 * blocked sites are detected and the browser uses the VLAN's DNS, an old
 * "not working" checklist does not hide answers, probes:check from a
 * container off the probe network does not fail every probe, removals in
 * UniFi are not put back, an allow only a paused filter supplies is kept,
 * and the installer can turn on website categories.
 */
class GoLiveFixesTest extends TestCase
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

    /** @return array<string, mixed> */
    private function report(array $overrides = []): array
    {
        return $overrides + [
            'hostname' => 'x', 'gateway' => '10.0.20.1', 'dns' => ['10.0.20.1'], 'dns_mode' => 'direct', 'browser_dns' => 'pinned',
            'interfaces' => [['name' => 'eth1', 'mac' => '02:aa:bb:cc:dd:ee', 'ipv4' => ['10.0.20.241/24'], 'default_route' => true]],
            'gateway_arp' => true, 'gateway_tcp' => true, 'dns_ok' => true, 'internet_ok' => true,
        ];
    }

    /** Students blocks two hostnames in UniFi and is linked; the student probe sits on its VLAN. */
    private function linkedStudents(): void
    {
        $filters = (new MockNetworkProvider)->contentFilters();
        $filters[0]['block'] = ['ometv.example', 'omegle.example'];
        Cache::forever('mock.content_filters', $filters);
        $this->actingAs($this->admin)->post(route('admin.policy.setup.import-all'));
        NetworkProfile::query()->where('key', 'student')->update(['probe_url' => 'http://probe-student:3000', 'unifi_network_id' => 'sim-student', 'enabled' => true]);
        NetworkProfile::query()->where('key', 'staff')->update(['probe_url' => 'http://probe-staff:3000', 'enabled' => false]);
    }

    private function step(string $key): ?array
    {
        $profile = NetworkProfile::query()->where('key', 'student')->sole();

        return collect(app(ProbeDiagnostics::class)->for($profile, refresh: true)['checks'])->firstWhere('key', $key);
    }

    public function test_the_checklist_saves_an_unrecognised_block_answer_and_checks_send_it(): void
    {
        $this->linkedStudents();
        Http::fake([
            'probe-student:3000/diagnose' => Http::response($this->report()),
            'probe-student:3000/resolve' => Http::response(['addresses' => ['10.9.9.9'], 'blocked' => false]),
            '*' => Http::response([], 404),
        ]);

        $blocked = $this->step('blocked');
        $this->assertSame('warn', $blocked['status']);
        $this->assertStringContainsString("omegle.example is on Students's block list, but this network's DNS answers 10.9.9.9", $blocked['detail']);
        $this->assertSame('ok', $this->step('browser_dns')['status']);
        $this->actingAs($this->admin)->get(route('admin.settings.show', 'profiles'))->assertOk()->assertSee('Save 10.9.9.9 as a block answer');

        $profile = NetworkProfile::query()->where('key', 'student')->sole();
        $this->actingAs($this->admin)->post(route('admin.probes.block-answer', $profile))->assertSessionHas('success');
        $this->assertSame(['10.9.9.9'], app(ProbeSettings::class)->blockAddresses());

        Http::fake(['*' => Http::response(['results' => [['status' => 'blocked', 'block_method' => 'dns', 'addresses' => ['10.9.9.9'], 'hosts' => []]]])]);
        $this->actingAs($this->admin)->post(route('checks.store'), ['websites' => 'ometv.example'])->assertOk();
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/check-batch') && $request['block_addresses'] === ['10.9.9.9']);
    }

    public function test_a_recognised_block_answer_passes_and_an_old_probe_image_is_named(): void
    {
        $this->linkedStudents();
        Http::fake([
            'probe-student:3000/diagnose' => Http::response($this->report()),
            'probe-student:3000/resolve' => Http::response(['addresses' => ['203.0.113.250'], 'blocked' => true]),
        ]);
        $this->assertSame('ok', $this->step('blocked')['status']);
    }

    public function test_an_old_probe_image_is_named(): void
    {
        $this->linkedStudents();
        Http::fake([
            'probe-student:3000/diagnose' => Http::response($this->report(['browser_dns' => null])),
            'probe-student:3000/resolve' => Http::response(['message' => 'Not found.'], 404),
        ]);
        $this->assertStringContainsString('before 2026.12.18', $this->step('browser_dns')['detail']);
        $this->assertStringContainsString('before 2026.12.18', $this->step('blocked')['detail']);
    }

    public function test_an_old_failing_checklist_does_not_hide_a_probe_that_answers(): void
    {
        $this->linkedStudents();
        $profile = NetworkProfile::query()->where('key', 'student')->sole();
        // The last checklist ran while the probe was unreachable; now it answers.
        $down = true;
        Http::fake(function ($request) use (&$down) {
            return match (true) {
                $down => Http::response([], 500),
                str_ends_with($request->url(), '/check-batch') => Http::response(['results' => [['status' => 'allowed', 'http_status' => 200, 'hosts' => []]]]),
                str_ends_with($request->url(), '/diagnose') => Http::response($this->report()),
                default => Http::response(['addresses' => ['203.0.113.250'], 'blocked' => true]),
            };
        });
        $this->assertSame('fail', app(ProbeDiagnostics::class)->for($profile, refresh: true)['state']);
        $down = false;

        $this->actingAs($this->admin)->post(route('checks.store'), ['websites' => 'example.org'])->assertOk()
            ->assertDontSee('This probe is not working')->assertSee('Allowed');
        $this->assertNotSame('fail', app(ProbeDiagnostics::class)->cached($profile)['state'], 'The checklist was run again');
    }

    public function test_probes_check_from_a_container_off_the_probe_network_keeps_the_last_results(): void
    {
        $this->linkedStudents();
        NetworkProfile::query()->where('key', 'staff')->update(['enabled' => true]);
        $offNetwork = false;
        Http::fake(function ($request) use (&$offNetwork) {
            if ($offNetwork) {
                throw new ConnectionException('cURL error 6: Could not resolve host: '.parse_url($request->url(), PHP_URL_HOST));
            }

            return str_ends_with($request->url(), '/diagnose') ? Http::response($this->report()) : Http::response(['addresses' => ['203.0.113.250'], 'blocked' => true]);
        });
        $this->artisan('probes:check')->assertSuccessful();
        $profile = NetworkProfile::query()->where('key', 'student')->sole();
        $before = app(ProbeDiagnostics::class)->cached($profile)['state'];

        $offNetwork = true;
        $this->artisan('probes:check')->expectsOutputToContain('not on the probe network')->assertFailed();
        $this->assertSame($before, app(ProbeDiagnostics::class)->cached($profile)['state']);
    }

    public function test_an_imported_entry_removed_in_unifi_is_deleted_even_when_setup_links_the_filter_again(): void
    {
        $filters = (new MockNetworkProvider)->contentFilters();
        $filters[0]['allow'] = ['example.edu', 'wistia.comfast.wistia.net'];
        Cache::forever('mock.content_filters', $filters);
        $this->actingAs($this->admin)->post(route('admin.policy.setup.import-all'));
        $students = FilterPolicy::query()->where('provider_reference', 'sim-filter-students')->sole();
        $this->assertContains('wistia.comfast.wistia.net', $this->engine->desired($students)['allow']);
        // A deliberate entry for the same kind of change is only kept out, never deleted.
        $this->engine->upsertEntry($this->engine->ownList($students, ListKind::Allow), 'kept.example.org', ['source' => EntrySource::Manual]);
        app(MockNetworkProvider::class)->writeContentFilterLists('sim-filter-students', ['example.edu', 'kept.example.org', 'wistia.comfast.wistia.net'], []);
        app(ControllerSync::class)->forget();
        app(SyncChecker::class)->checkOne($students->refresh(), refresh: true);

        // Fixed by hand in UniFi, then Guided setup saved again (which links every filter again).
        app(MockNetworkProvider::class)->writeContentFilterLists('sim-filter-students', ['example.edu', 'wistia.com', 'fast.wistia.net'], []);
        app(ControllerSync::class)->forget();
        $filter = collect(app(ControllerSync::class)->state(true)['filters'])->firstWhere('id', 'sim-filter-students');
        app(ControllerSync::class)->link($filter, $students->refresh(), $this->admin);

        $this->assertFalse(PolicyEntry::query()->where('domain', 'wistia.comfast.wistia.net')->exists(), 'Deleted from the lists');
        $this->assertTrue(PolicyEntry::query()->where('domain', 'kept.example.org')->exists(), 'An entry added on purpose stays in its list');
        $report = $this->engine->reconcile($students->refresh());
        $this->assertNotContains('wistia.comfast.wistia.net', $report['allow_add'], 'Publishing does not put it back');
        $this->assertNotContains('kept.example.org', $report['allow_add']);
        $this->assertContains('wistia.com', $this->engine->desired($students)['allow']);
    }

    public function test_an_allow_only_a_paused_filter_supplies_is_kept_and_flagged(): void
    {
        $filters = (new MockNetworkProvider)->contentFilters();
        $filters[0]['allow'] = ['example.edu', 'copilot.example'];
        $filters[] = ['id' => 'sim-sitewide', 'name' => 'Allow (Sitewide)', 'enabled' => false, 'allow' => ['copilot.example'], 'block' => [], 'network_ids' => ['sim-student', 'sim-staff'], 'categories' => [], 'safe_search' => []];
        Cache::forever('mock.content_filters', $filters);
        $sitewide = FilterPolicy::query()->create(['name' => 'Allow (Sitewide)', 'slug' => 'allow-sitewide', 'audience' => 'staff', 'role' => 'sitewide', 'enabled' => true]);
        $this->engine->upsertEntry($this->engine->ownList($sitewide, ListKind::Allow), 'copilot.example', ['source' => EntrySource::Manual]);
        $students = FilterPolicy::query()->main()->where('audience', 'student')->firstOrFail();
        app(ControllerSync::class)->link(collect(app(ControllerSync::class)->state(true)['filters'])->firstWhere('id', 'sim-sitewide'), $sitewide, $this->admin);
        app(ControllerSync::class)->link(collect(app(ControllerSync::class)->state()['filters'])->firstWhere('id', 'sim-filter-students'), $students, $this->admin);
        PolicyEntry::query()->where('domain', 'copilot.example')->whereHas('policyList', fn ($query) => $query->where('filter_policy_id', $students->id))->delete();

        $report = $this->engine->reconcile($students->refresh());
        $this->assertNotContains('copilot.example', $report['allow_remove']);
        $this->assertSame(['copilot.example' => ['Allow (Sitewide)']], $report['allow_paused']);
        $checked = app(SyncChecker::class)->checkOne($students, refresh: false);
        $this->assertStringContainsString('copilot.example is only allowed by Allow (Sitewide), which is paused in UniFi', $checked->sync_details['summary']);
        $this->actingAs($this->admin)->get(route('admin.policy.policies.show', $students))->assertOk()->assertSee('Kept because the filter that provides them is paused');
    }

    public function test_the_installer_can_test_and_turn_on_website_categories(): void
    {
        Cache::flush();
        Setting::query()->where('key', 'installation.completed')->delete();
        IntegrationConfig::query()->where('provider', DomainCategorizer::PROVIDER)->delete();
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => ['details_0' => ['categories' => [['name' => 'Education']]]]])]);

        $this->post(route('install.categories-test'), ['categories_source' => 'radar', 'categories_token' => 'cf-token'])->assertRedirect(route('install.show', 7));
        $this->assertSame('cf-token', session('installation')['categories_token']);
        $this->assertArrayHasKey('categories_test', session('installation'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.cloudflare.com') && $request->hasHeader('Authorization', 'Bearer cf-token'));
        $this->get(route('install.show', 7))->assertOk()->assertSee('Look up website categories with Cloudflare')->assertSee('Saved; leave blank to keep it');
    }
}
