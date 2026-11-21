<?php

namespace Tests\Feature;

use App\Models\FirewallList;
use App\Models\IntegrationConfig;
use App\Models\User;
use App\Services\Network\MockNetworkProvider;
use App\Services\Policy\FirewallListPusher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

class FirewallGroupTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::flush();
        $this->installWithPolicies('active');
        IntegrationConfig::query()->where('provider', 'mock')->update(['configuration' => ['max_ip_group_entries' => 100, 'auto_push' => true]]);
    }

    private function list(int $count): FirewallList
    {
        return FirewallList::query()->create(['name' => 'Threats', 'family' => 'ipv4', 'enabled' => true, 'max_entries' => 1000,
            'manual_entries' => array_map(fn (int $index) => '198.51.'.intdiv($index, 250).'.'.($index % 250 + 1), range(0, $count - 1))]);
    }

    private function controllerGroups(): array
    {
        return (new MockNetworkProvider)->rawIpGroups();
    }

    public function test_a_large_list_is_split_across_groups_with_blocking_rules_kept_in_step(): void
    {
        $admin = User::factory()->admin()->create();
        $list = $this->list(250);

        $this->actingAs($admin)->post(route('admin.policy.blocklists.firewall.create-group', $list))->assertSessionHas('success');
        $list->refresh();
        $this->assertCount(3, $list->groups);
        $this->assertSame(['Threats (1 of 3)', 'Threats (2 of 3)', 'Threats (3 of 3)'], array_column($list->groups, 'name'));
        $this->assertSame([100, 100, 50], array_column($list->groups, 'count'));

        $this->actingAs($admin)->get(route('admin.policy.blocklists.index'))->assertOk()->assertSee('Create blocking rules in UniFi')->assertSee('Hotspot');
        $this->actingAs($admin)->post(route('admin.policy.blocklists.firewall.rules', $list), ['zones' => ['zone-internal', 'zone-hotspot'], 'block_incoming' => '1'])->assertSessionHas('success');
        $rules = Cache::get('mock.firewall_rules');
        $this->assertCount(9, $rules, '3 groups x (2 zones + incoming)');
        $this->assertSame(['zone-external'], collect($rules)->where('incoming', false)->pluck('destination')->unique()->values()->all());

        // Unchanged: nothing is sent.
        $this->assertTrue(app(FirewallListPusher::class)->push($list->refresh(), null)['skipped'] ?? false);

        // Shrinks: surplus groups and their rules go.
        $list->update(['manual_entries' => array_slice($list->manual_entries, 0, 150)]);
        $result = app(FirewallListPusher::class)->push($list->refresh(), null);
        $this->assertTrue($result['ok']);
        $list->refresh();
        $this->assertCount(2, $list->groups);
        $this->assertCount(6, Cache::get('mock.firewall_rules'));
        $this->assertCount(2, collect($this->controllerGroups())->filter(fn ($group) => str_starts_with($group['name'], 'Threats')));

        // Untick every zone: the rules are removed.
        $this->actingAs($admin)->post(route('admin.policy.blocklists.firewall.rules', $list), ['zones' => []]);
        $this->assertSame([], Cache::get('mock.firewall_rules'));

        $this->actingAs($admin)->post(route('admin.policy.blocklists.firewall.rules', $list), ['zones' => ['zone-internal']]);
        $this->actingAs($admin)->delete(route('admin.policy.blocklists.firewall.destroy', $list));
        $this->assertSame([], Cache::get('mock.firewall_rules'), 'Deleting the list removes the rules it created');
    }

    public function test_scheduled_refresh_only_pushes_when_the_list_changed(): void
    {
        $list = $this->list(20);
        app(FirewallListPusher::class)->push($list, null, force: true);

        $this->artisan('blocklists:refresh')->expectsOutputToContain('unchanged since the last push')->assertSuccessful();
    }
}
