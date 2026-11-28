<?php

namespace Tests\Feature;

use App\Models\FirewallList;
use App\Models\IntegrationConfig;
use App\Models\User;
use App\Services\Network\MockNetworkProvider;
use App\Services\Policy\FirewallListPusher;
use App\Services\Policy\IpFeedProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

/** UniFi limits an IP list by its stored size (about 126 KB), not entries: a refused part is split smaller and pushed again. */
class IpListBytesLimitTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::flush();
        $this->installWithPolicies('active');
    }

    public function test_a_list_unifi_refuses_as_too_large_is_split_smaller_and_pushed(): void
    {
        $integration = IntegrationConfig::query()->where('provider', 'mock')->sole();
        $integration->forceFill(['configuration' => ['max_ip_group_entries' => 40000, 'firewall_rules' => 'lists_only'] + (array) $integration->configuration])->save();
        Cache::forever(MockNetworkProvider::BYTE_LIMIT_KEY, 126000);
        $entries = array_map(fn (int $i) => long2ip(ip2long('8.0.0.0') + $i * 3), range(0, 59999));
        $list = FirewallList::query()->create(['name' => 'Abuse', 'family' => 'ipv4', 'manual_entries' => $entries, 'max_entries' => 100000]);

        $result = app(FirewallListPusher::class)->push($list, User::factory()->admin()->create());

        $this->assertTrue($result['ok'], $result['message']);
        $list->refresh();
        $this->assertSame(22680, $list->max_part_entries);
        $this->assertCount(3, $list->groups);
        $this->assertSame('Abuse (1 of 3)', $list->groups[0]['name']);
        $this->assertStringContainsString('UniFi took at most about 22,680', $result['message']);
        $groups = collect((new MockNetworkProvider)->ipGroups())->whereIn('id', collect($list->groups)->pluck('id'));
        $this->assertSame(60000, $groups->sum('count'), 'every address is in UniFi');
        $this->assertFalse(collect((new MockNetworkProvider)->ipGroups())->contains(fn (array $group) => str_contains($group['name'], 'of 2')), 'no half-pushed parts left behind');

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('admin.policy.blocklists.index'))->assertOk()->assertSee('split at 22,680 entries')->assertSee('Push to UniFi')->assertDontSee('Push to controller');

        // Changing the setting tries the new size again.
        $this->actingAs($admin)->put(route('admin.settings.firewall-lists'), ['max_ip_group_entries' => 30000, 'firewall_rules' => 'lists_only']);
        $this->assertNull($list->refresh()->max_part_entries);
    }

    public function test_a_failed_push_is_shown_next_to_the_button(): void
    {
        $list = FirewallList::query()->create(['name' => 'Empty', 'family' => 'ipv4', 'manual_entries' => [], 'max_entries' => 100]);
        app(FirewallListPusher::class)->push($list, null);
        $this->actingAs(User::factory()->admin()->create())->get(route('admin.policy.blocklists.index'))->assertSee('The last push failed')->assertSee('Not in UniFi yet');
    }

    public function test_the_feed_row_says_what_the_whole_24_merge_costs(): void
    {
        // Three addresses in each of 100 /24s: taken whole, they're 100 entries covering 25,600 addresses.
        $values = [];
        foreach (range(0, 99) as $block) {
            foreach ([1, 50, 200] as $host) {
                $values[] = long2ip(ip2long('45.10.0.0') + ($block * 2 + 1) * 256 + $host);
            }
        }
        $stats = app(IpFeedProcessor::class)->process($values, 'ipv4', 1000, ['collapse' => 3])['stats'];

        $this->assertSame(100, $stats['merged_to']);
        $this->assertSame(25600, $stats['covered']);
        $this->assertSame(300, $stats['listed']);
        $this->assertSame(300, $stats['without_collapse']);
        $this->assertStringContainsString('covering 25,600 addresses, 25,300 more than the feed lists, without Whole /24: 300 entries (2 UniFi lists)', IpFeedProcessor::describe($stats, 200));
    }
}
