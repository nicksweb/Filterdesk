<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\IntegrationConfig;
use App\Models\User;
use App\Services\Network\IpListSizeTester;
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
        $this->actingAs($admin)->get(route('admin.settings.show', 'network'))->assertOk()->assertSee('Last test')->assertSee('50,000')->assertSee('random public addresses')->assertSee('Use 45,000 per list');

        $this->actingAs($admin)->post(route('admin.settings.ip-list-size-test.use'))->assertSessionHas('success');
        $this->assertSame(45000, IntegrationConfig::query()->where('provider', 'mock')->sole()->configuration['max_ip_group_entries']);
        $this->assertSame(45000, app(FirewallListPusher::class)->groupLimit());

        $this->actingAs(User::factory()->it()->create())->post(route('admin.settings.ip-list-size-test'), ['sizes' => [20000], 'understood' => '1'])->assertForbidden();
        $this->artisan('filterdesk:test-ip-list-size', ['--sizes' => '20000', '--force' => true])->assertSuccessful();
    }

    public function test_a_size_unifi_refuses_for_its_bytes_gives_a_size_with_a_margin(): void
    {
        Cache::forever(MockNetworkProvider::BYTE_LIMIT_KEY, 126000);
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('admin.settings.ip-list-size-test'), ['sizes' => [20000, 30000, 50000], 'understood' => '1'])->assertSessionHas('success');

        $rows = app(IpListSizeTester::class)->last()['rows'];
        $this->assertCount(2, $rows, 'it stops at the first refusal');
        $this->assertTrue($rows[0]['ok']);
        $this->assertSame(20000, $rows[0]['count'], 'read back from UniFi, not the cache');
        $this->assertStringContainsString('over its limit of 126,000', $rows[1]['message']);
        $this->assertSame(22000, app(IpListSizeTester::class)->suggested());
        $this->actingAs($admin)->get(route('admin.settings.show', 'network'))->assertSee('Use 22,000 per list');
    }
}
