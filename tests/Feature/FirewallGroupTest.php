<?php

namespace Tests\Feature;

use App\Models\FirewallList;
use App\Models\IntegrationConfig;
use App\Models\User;
use App\Services\Network\MockNetworkProvider;
use App\Services\Network\ProviderRequestFailed;
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
        IntegrationConfig::query()->where('provider', 'mock')->update(['configuration' => ['max_ip_group_entries' => 100, 'auto_push' => true, 'firewall_rules' => 'manage']]);
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
        $this->assertCount(15, $rules, '3 groups x (2 zones out + 2 zones and the Gateway in)');
        $this->assertSame(['zone-external'], collect($rules)->where('incoming', false)->pluck('destination')->unique()->values()->all());
        $this->assertEqualsCanonicalizing(['zone-internal', 'zone-hotspot', 'zone-gateway'], collect($rules)->where('incoming', true)->pluck('destination')->unique()->values()->all());
        $this->assertSame(['zone-external'], collect($rules)->where('incoming', true)->pluck('source')->unique()->values()->all());

        // Unchanged: nothing is sent.
        $this->assertTrue(app(FirewallListPusher::class)->push($list->refresh(), null)['skipped'] ?? false);

        // Shrinks: surplus groups and their rules go.
        $list->update(['manual_entries' => array_slice($list->manual_entries, 0, 150)]);
        $result = app(FirewallListPusher::class)->push($list->refresh(), null);
        $this->assertTrue($result['ok']);
        $list->refresh();
        $this->assertCount(2, $list->groups);
        $this->assertCount(10, Cache::get('mock.firewall_rules'));
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

    public function test_the_page_shows_each_zones_networks_and_the_rules_it_will_create(): void
    {
        $admin = User::factory()->admin()->create();
        $list = $this->list(150);
        app(FirewallListPusher::class)->push($list, null, force: true);

        $this->actingAs($admin)->get(route('admin.policy.blocklists.index'))->assertOk()
            ->assertSee('Staff Access, Student Access')
            ->assertSee('Rules that will be created with the zones ticked by default: 2')
            ->assertSee('Block Threats (1 of 2) (Internal)')
            ->assertSee('Put these rules first');
    }

    public function test_rules_deleted_in_unifi_are_recreated_and_can_be_placed_first(): void
    {
        $admin = User::factory()->admin()->create();
        $list = $this->list(20);
        app(FirewallListPusher::class)->push($list, null, force: true);
        $this->actingAs($admin)->post(route('admin.policy.blocklists.firewall.rules', $list->refresh()), ['zones' => ['zone-internal'], 'rules_first' => '1']);
        $ruleId = $list->refresh()->rules[0]['id'];
        $this->assertSame([$ruleId], Cache::get('mock.firewall_first'));

        // Someone deletes the rule in UniFi: the next scheduled push puts it back although the list is unchanged.
        Cache::forever('mock.firewall_rules', []);
        $result = app(FirewallListPusher::class)->push($list->refresh(), null);
        $this->assertArrayNotHasKey('skipped', $result);
        $this->assertStringContainsString('Recreated 1 rule', $result['message']);
        $this->assertCount(1, Cache::get('mock.firewall_rules'));
        $this->assertTrue(app(FirewallListPusher::class)->push($list->refresh(), null)['skipped'] ?? false);
    }

    public function test_an_allow_rule_above_the_block_is_reported(): void
    {
        $list = $this->list(20);
        $list->forceFill(['rules' => [['id' => 'ours', 'group' => 'g', 'zone' => 'zone-internal', 'incoming' => false, 'name' => 'Block Threats (Internal)']]])->save();
        $live = [
            ['id' => 'nas', 'name' => 'Allow NAS', 'action' => 'ALLOW', 'enabled' => true, 'index' => 10000, 'source' => 'zone-internal', 'destination' => 'zone-external', 'user' => true],
            ['id' => 'other-pair', 'name' => 'Allow VPN', 'action' => 'ALLOW', 'enabled' => true, 'index' => 10000, 'source' => 'zone-vpn', 'destination' => 'zone-external', 'user' => true],
            ['id' => 'ours', 'name' => 'Block Threats (Internal)', 'action' => 'BLOCK', 'enabled' => true, 'index' => 10001, 'source' => 'zone-internal', 'destination' => 'zone-external', 'user' => true],
            ['id' => 'after', 'name' => 'Allow later', 'action' => 'ALLOW', 'enabled' => true, 'index' => 10002, 'source' => 'zone-internal', 'destination' => 'zone-external', 'user' => true],
        ];

        $this->assertSame([['name' => 'Allow NAS', 'before' => 'Block Threats (Internal)']], app(FirewallListPusher::class)->rulesAhead($list, $live));
    }

    public function test_rules_are_refused_without_an_external_zone(): void
    {
        $this->expectException(ProviderRequestFailed::class);
        app(FirewallListPusher::class)->wantedRules($this->list(5), [['id' => 'g']], [['id' => 'z', 'name' => 'Internal']], ['z']);
    }
}
