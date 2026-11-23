<?php

namespace Tests\Feature;

use App\Models\FilterPolicy;
use App\Models\NetworkAssignment;
use App\Models\User;
use App\Services\Network\InspectionCertificate;
use App\Services\Network\MockNetworkProvider;
use App\Services\Policy\BaselineGuard;
use App\Services\Policy\ControllerLearner;
use App\Services\Policy\ControllerSync;
use App\Services\Policy\PolicyEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

/**
 * Learning a console with stacked filters (site-wide allow and block filters
 * on every network, plus a group filter per type), publishing site-wide
 * blocks to one filter only, network kinds, and the HTTPS inspection
 * certificate.
 */
class LearnUniFiTest extends TestCase
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

        $all = ['sim-staff', 'sim-student', 'sim-guest'];
        $filters = (new MockNetworkProvider)->contentFilters();
        $filters[] = ['id' => 'sim-allow-site', 'name' => 'Allow (Sitewide)', 'enabled' => true, 'allow' => ['school.example.edu'], 'block' => [], 'network_ids' => $all, 'categories' => ['ADULT'], 'safe_search' => []];
        $filters[] = ['id' => 'sim-block-site', 'name' => 'Block (Sitewide)', 'enabled' => true, 'allow' => [], 'block' => ['bad.example.net'], 'network_ids' => $all, 'categories' => ['ADULT'], 'safe_search' => []];
        $filters[] = ['id' => 'sim-block-security', 'name' => 'Block (Sitewide) Security', 'enabled' => false, 'allow' => [], 'block' => ['scan.example.net'], 'network_ids' => $all, 'categories' => ['ADULT'], 'safe_search' => [], 'schedule' => 'ALWAYS'];
        Cache::forever('mock.content_filters', $filters);
    }

    public function test_it_learns_site_wide_layers_groups_and_which_one_takes_the_blocks(): void
    {
        $learned = app(ControllerLearner::class)->learn(app(ControllerSync::class)->state(true));

        $this->assertSame('sitewide', $learned['filters']['sim-allow-site']['role']);
        $this->assertSame('sitewide', $learned['filters']['sim-block-site']['role']);
        $this->assertSame('sim-block-site', $learned['carrier'], 'The plainest block filter that is not paused');
        $this->assertSame('group', $learned['filters']['sim-filter-staff']['role']);
        $this->assertSame('staff', $learned['filters']['sim-filter-staff']['audience']);

        $page = $this->actingAs($this->admin)->get(route('admin.policy.setup.show'))->assertOk();
        $page->assertSee('How your UniFi console will look')->assertSee('Site-wide layer · takes the site-wide blocks')->assertSee('Paused in UniFi')->assertSee('What Filterdesk learned from UniFi');
    }

    public function test_stacked_filters_import_without_drift_and_site_wide_blocks_go_to_one_filter(): void
    {
        $this->actingAs($this->admin)->post(route('admin.policy.setup.import-all'))->assertSessionHas('success');

        $blockSite = FilterPolicy::query()->where('provider_reference', 'sim-block-site')->sole();
        $allowSite = FilterPolicy::query()->where('provider_reference', 'sim-allow-site')->sole();
        $students = FilterPolicy::query()->where('provider_reference', 'sim-filter-students')->sole();
        $this->assertTrue($blockSite->isSitewide() && $blockSite->receives_site_blocks);
        $this->assertTrue($allowSite->isSitewide() && ! $allowSite->receives_site_blocks);
        $this->assertSame($students->id, NetworkAssignment::query()->where('provider_network_id', 'sim-student')->value('filter_policy_id'), 'Networks stay with their group');

        // A new site-wide block goes only to Block (Sitewide); a site-wide allow goes everywhere.
        $this->engine->upsertEntry($this->engine->defaultBlockList(null), 'newly-blocked.example.org', ['source' => 'manual']);
        $this->engine->upsertEntry($this->engine->defaultAllowList(null), 'newly-allowed.example.org', ['source' => 'manual']);
        $this->assertContains('newly-blocked.example.org', $this->engine->desired($blockSite)['block']);
        $this->assertNotContains('newly-blocked.example.org', $this->engine->desired($students)['block']);
        $this->assertNotContains('newly-blocked.example.org', $this->engine->desired($allowSite)['block']);
        $this->assertContains('newly-allowed.example.org', $this->engine->desired($students)['allow']);
        $this->assertContains('newly-allowed.example.org', $this->engine->desired($allowSite)['allow']);
        $this->assertNotContains('bad.example.net', $this->engine->desired($students)['block'], 'Imported entries stay in their own filter');

        $this->artisan('policy:check-sync')->assertSuccessful();
        $this->assertNotSame('drift', $students->refresh()->sync_status, 'Site-wide layers on the same networks are expected');
        $this->assertStringNotContainsString('Shares', (string) ($students->sync_details['summary'] ?? ''));

        $this->actingAs($this->admin)->get(route('admin.policy.index'))->assertOk()->assertSee('Content filters')->assertSee('Site-wide layer · takes the site-wide blocks');
    }

    public function test_network_kinds_and_protecting_a_network_without_a_group_filter(): void
    {
        $learner = app(ControllerLearner::class);
        $this->assertSame('device', $learner->kindFor('IT-MGMT', false));
        $this->assertSame('device', $learner->kindFor('IT-APs-Boarding', true));
        $this->assertSame('user', $learner->kindFor('User-Student-Wifi', false));
        $this->assertSame('user', $learner->kindFor('Wireless', false));

        $this->actingAs($this->admin)->post(route('admin.policy.setup.import-all'));
        $this->actingAs($this->admin)->get(route('admin.policy.networks.index'))->assertOk()
            ->assertSee('A user network with only site-wide filtering')->assertSee('Needs attention');

        $this->actingAs($this->admin)->post(route('admin.policy.networks.protect'), ['networks' => ['sim-guest']])->assertSessionHas('success');
        $students = collect(app(MockNetworkProvider::class)->contentFilters())->firstWhere('id', 'sim-filter-students');
        $this->assertContains('sim-guest', $students['network_ids']);
    }

    public function test_the_inspection_certificate_is_offered_publicly_and_a_private_key_is_refused(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'UniFi SSL Certificate Authority', 'organizationName' => 'Ubiquiti Inc.'], $key);
        openssl_x509_export(openssl_csr_sign($csr, null, $key, 365), $pem);
        openssl_pkey_export($key, $privatePem);

        $this->get(route('certificate.show'))->assertNotFound();
        $this->actingAs($this->admin)->put(route('admin.settings.update', 'certificate'), ['certificate_text' => $privatePem.$pem])->assertSessionHasErrors('certificate_file');
        $this->actingAs($this->admin)->put(route('admin.settings.update', 'certificate'), ['certificate_text' => $pem])->assertSessionHasNoErrors();

        auth()->logout();
        $this->get(route('certificate.show'))->assertOk()->assertSee('UniFi SSL Certificate Authority')->assertSee('Trusted Root Certification Authorities');
        $this->get(route('certificate.download', 'crt'))->assertOk()->assertHeader('Content-Type', 'application/x-x509-ca-cert');
        $this->assertTrue(app(InspectionCertificate::class)->matches('UniFi SSL Certificate Authority, Ubiquiti Inc.'));
        $this->assertFalse(app(InspectionCertificate::class)->matches('WE2, Google Trust Services'));
    }

    public function test_a_named_group_on_most_networks_stays_a_group_and_baseline_prefers_the_always_on_filter(): void
    {
        $filters = app(MockNetworkProvider::class)->contentFilters();
        $filters[] = ['id' => 'sim-school-time', 'name' => 'Student - School Time', 'enabled' => true, 'allow' => [], 'block' => [], 'network_ids' => ['sim-staff', 'sim-student', 'sim-guest'], 'categories' => ['ADULT'], 'safe_search' => [], 'schedule' => 'CUSTOM'];
        Cache::forever('mock.content_filters', $filters);

        $learned = app(ControllerLearner::class)->learn(app(ControllerSync::class)->state(true));
        $this->assertSame('group', $learned['filters']['sim-school-time']['role'], 'Named for students, so a group, not site-wide');
        $this->assertTrue($learned['filters']['sim-school-time']['scheduled']);

        $this->actingAs($this->admin)->post(route('admin.policy.setup.import-all'));
        $this->assertSame('sim-filter-students', app(BaselineGuard::class)->policy()?->provider_reference, 'The always-on Students filter protects unfiltered networks');
    }

    public function test_filters_are_added_to_and_removed_from_a_network_and_mismatches_offer_the_fix(): void
    {
        $this->actingAs($this->admin)->post(route('admin.policy.setup.import-all'));
        $guest = NetworkAssignment::query()->where('provider_network_id', 'sim-guest')->sole();
        $onGuest = fn () => collect(app(MockNetworkProvider::class)->contentFilters())->filter(fn (array $filter) => in_array('sim-guest', $filter['network_ids'], true))->pluck('id')->all();

        $this->actingAs($this->admin)->post(route('admin.policy.networks.filter', $guest), ['filter_id' => 'sim-filter-staff'])->assertSessionHas('success');
        $this->assertContains('sim-filter-staff', $onGuest());
        $this->actingAs($this->admin)->post(route('admin.policy.networks.filter', $guest), ['filter_id' => 'sim-filter-staff', 'remove' => '1'])->assertSessionHas('success');
        $this->assertNotContains('sim-filter-staff', $onGuest());

        // Mapped to Staff here but filtered as Students in UniFi: the row offers "Map to Students".
        NetworkAssignment::query()->where('provider_network_id', 'sim-student')->update(['filter_policy_id' => FilterPolicy::query()->where('provider_reference', 'sim-filter-staff')->value('id')]);
        $this->actingAs($this->admin)->get(route('admin.policy.networks.index'))->assertOk()->assertSee('filtered as Students in UniFi')->assertSee('Map to Students');

        // One "Managed by" control: an existing policy, or a new one for a type.
        $this->actingAs($this->admin)->post(route('admin.policy.networks.link'), ['filter_id' => 'sim-block-site', 'managed_by' => 'new:guest'])->assertSessionHas('success');
        $this->assertSame('guest', FilterPolicy::query()->where('provider_reference', 'sim-block-site')->value('audience'));
    }
}
