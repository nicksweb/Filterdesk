<?php

namespace Tests\Feature;

use App\Models\FilterPolicy;
use App\Models\FirewallList;
use App\Models\IntegrationConfig;
use App\Models\NetworkAssignment;
use App\Models\User;
use App\Models\WebsiteRequest;
use App\Services\Domains\DomainCategorizer;
use App\Support\Reach;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

/**
 * With UniFi unreachable, every probe offline and the category service down,
 * every page still loads and every scheduled job finishes: the app reports
 * the outage instead of failing.
 */
class OfflineResilienceTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::flush();
        Sleep::fake();
        $this->installWithPolicies('active');

        IntegrationConfig::query()->where('provider', 'mock')->update(['enabled' => false]);
        IntegrationConfig::query()->create(['provider' => 'unifi', 'name' => 'UniFi Network', 'mode' => 'active', 'enabled' => true,
            'configuration' => ['connection' => 'local', 'controller_url' => 'https://unifi.offline.test', 'auto_push' => true, 'controller_authoritative' => true],
            'credentials' => ['api_key' => 'k']]);
        IntegrationConfig::query()->create(['provider' => DomainCategorizer::PROVIDER, 'name' => 'Website categories', 'mode' => 'read_only', 'enabled' => true,
            'configuration' => ['source' => 'radar'], 'credentials' => ['token' => 't']]);

        // Linked policies, mapped networks, a firewall list and an open request, as on a live install.
        FilterPolicy::query()->where('audience', 'staff')->update(['provider_reference' => 'f-staff', 'provider_name' => 'Staff', 'snapshot_at' => now()]);
        NetworkAssignment::query()->create(['provider_network_id' => 'n1', 'name' => 'Staff', 'vlan' => 10, 'subnet' => '10.0.10.1/24', 'filter_policy_id' => FilterPolicy::query()->where('audience', 'staff')->value('id')]);
        FirewallList::query()->create(['name' => 'Threats', 'family' => 'ipv4', 'enabled' => true, 'max_entries' => 100, 'manual_entries' => ['198.51.100.1'],
            'provider_reference' => 'g1', 'groups' => [['id' => 'g1', 'name' => 'Threats', 'count' => 1]], 'rule_zones' => ['z1']]);
        $this->admin = User::factory()->admin()->create();

        // Everything outside the app is down: UniFi, the probes and Cloudflare.
        Http::fake(['*' => Http::failedConnection()]);
    }

    public function test_every_page_loads_when_unifi_probes_and_categories_are_offline(): void
    {
        $request = $this->pendingRequest(['offline.example.org'], Reach::types('staff'));
        $policy = FilterPolicy::query()->where('audience', 'staff')->sole();
        $pages = [
            route('dashboard'), route('admin.policy.index'), route('admin.policy.networks.index'), route('admin.policy.networks.index', ['refresh' => 1]),
            route('admin.policy.setup.show'), route('admin.policy.setup.show', ['refresh' => 1]), route('admin.policy.setup.compose'),
            route('admin.policy.blocklists.index'), route('admin.policy.scan.show'), route('admin.policy.policies.show', $policy),
            route('admin.diagnostics'), route('admin.audit'), route('admin.users.index'), route('checks.create'),
            route('requests.index'), route('requests.create', ['domains' => 'offline.example.org']), route('requests.show', $request),
            route('admin.settings.show', 'profiles'), route('admin.settings.show', 'network'), route('admin.settings.show', 'categories'),
            route('admin.settings.show', 'templates'), route('admin.settings.show', 'types'), route('brand.icon'),
            route('admin.policy.changes.index'), route('admin.policy.explorer', ['site' => 'offline.example.org']), route('admin.policy.types.show', 'staff'),
            route('admin.insights'), route('admin.health'), route('reviews.index'), route('why', ['site' => 'offline.example.org']),
            route('admin.settings.show', 'calendar'), route('admin.settings.show', 'integrations'),
        ];

        foreach ($pages as $page) {
            $status = $this->actingAs($this->admin)->get($page)->status();
            $this->assertLessThan(500, $status, "{$page} returned {$status} with everything offline");
        }
    }

    public function test_actions_report_the_outage_instead_of_failing(): void
    {
        $policy = FilterPolicy::query()->where('audience', 'staff')->sole();
        $request = $this->pendingRequest(['offline.example.org'], Reach::types('staff'));

        // Approving still records the decision; the push failure is noted for a retry.
        $this->actingAs($this->admin)->post(route('it.requests.action', $request), ['action' => 'approve', 'smart' => '1'])->assertRedirect()->assertSessionHas('success');
        $this->assertNotSame('pending', $request->refresh()->status->value);

        foreach ([
            route('admin.policy.policies.push', $policy), route('admin.policy.networks.push'), route('admin.policy.networks.check'),
            route('admin.policy.policies.pull', $policy), route('admin.policy.blocklists.firewall.push', FirewallList::query()->sole()),
            route('admin.settings.test-network'), route('admin.settings.test-categories'), route('admin.policy.changes.publish'),
        ] as $action) {
            $status = $this->actingAs($this->admin)->post($action, ['domain' => 'offline.example.org'])->status();
            $this->assertLessThan(500, $status, "POST {$action} returned {$status} with everything offline");
        }

        // A website check with every probe offline returns a page with errors, not a crash.
        $this->actingAs($this->admin)->post(route('checks.store'), ['websites' => 'offline.example.org'])->assertOk();
        $this->assertSame(1, WebsiteRequest::query()->count());
    }

    public function test_scheduled_jobs_finish_when_everything_is_offline(): void
    {
        $this->pendingRequest(['offline.example.org'], Reach::types('staff'));
        foreach (['policy:push', 'policy:push --boundaries', 'policy:check-sync', 'blocklists:refresh', 'probes:check', 'categories:guard --refresh', 'requests:expire', 'requests:remind', 'policy:digest', 'exceptions:review'] as $command) {
            $exit = $this->artisan($command)->run();
            $this->assertContains($exit, [0, 1], "{$command} crashed with everything offline");
        }
    }
}
