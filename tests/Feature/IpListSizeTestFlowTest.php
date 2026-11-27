<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\IntegrationConfig;
use App\Models\User;
use App\Services\Network\MockNetworkProvider;
use App\Services\Policy\FirewallListPusher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

/** Settings → Network provider → Test larger UniFi IP lists: started by an admin in the browser, results set the list size. */
class IpListSizeTestFlowTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::flush();
        $this->installWithPolicies('active');
    }

    public function test_an_admin_runs_the_test_in_the_browser_and_uses_the_result(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('admin.settings.show', 'network'))->assertOk()->assertSee('Test larger UniFi IP lists')->assertSee('Run the test');

        $this->actingAs($admin)->post(route('admin.settings.ip-list-size-test'), ['sizes' => [20000, 50000]])->assertSessionHasErrors('understood');
        $this->actingAs($admin)->post(route('admin.settings.ip-list-size-test'), ['sizes' => [20000, 50000], 'understood' => '1'])->assertSessionHas('success');

        // The queue runs it straight away in tests; the temporary lists are gone again.
        $this->assertFalse(collect((new MockNetworkProvider)->ipGroups())->contains(fn (array $group) => str_starts_with($group['name'], 'Filterdesk size test')));
        $this->assertTrue(AuditEvent::query()->where('action', 'firewall.ip_list_size_tested')->exists());
        $this->actingAs($admin)->get(route('admin.settings.show', 'network'))->assertOk()->assertSee('Last test')->assertSee('50,000')->assertSee('Use 50,000 per list');

        $this->actingAs($admin)->post(route('admin.settings.ip-list-size-test.use'))->assertSessionHas('success');
        $this->assertSame(50000, IntegrationConfig::query()->where('provider', 'mock')->sole()->configuration['max_ip_group_entries']);
        $this->assertSame(50000, app(FirewallListPusher::class)->groupLimit());

        $this->actingAs(User::factory()->it()->create())->post(route('admin.settings.ip-list-size-test'), ['sizes' => [20000], 'understood' => '1'])->assertForbidden();
        $this->artisan('filterdesk:test-ip-list-size', ['--sizes' => '20000', '--force' => true])->assertSuccessful();
    }
}
