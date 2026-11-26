<?php

namespace Tests\Feature;

use App\Models\FirewallList;
use App\Models\ListSource;
use App\Models\User;
use App\Services\Policy\IpFeedProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

/**
 * Large IP feeds (the AbuseIPDB 30-day list) processed to fit a UniFi
 * firewall list: reserved and allowed addresses dropped, busy /24s taken
 * whole, neighbours merged, and a cap that keeps the widest ranges first.
 */
class IpFeedProcessingTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::flush();
        $this->installWithPolicies('active');
    }

    public function test_addresses_are_filtered_merged_collapsed_and_capped(): void
    {
        FirewallList::query()->create(['name' => 'Allow', 'family' => 'ipv4', 'kind' => 'allow', 'max_entries' => 100,
            'allow_entries' => [['id' => 'a', 'value' => '9.9.9.9', 'type' => 'ip']]]);
        $values = ['10.1.2.3', '192.168.1.1', '203.0.113.9', '100.64.0.1', '9.9.9.9',
            '45.1.1.1', '45.1.1.2', '45.1.1.3', // three in one /24: taken whole at 3+
            '60.0.0.0', '60.0.0.1', '60.0.0.2', '60.0.0.3', // ... and these four are 60.0.0.0/30 on their own
            '80.1.0.0/24', '80.1.1.0/24', // neighbouring /24s: 80.1.0.0/23
            '81.0.0.1', '82.0.0.1'];

        $result = app(IpFeedProcessor::class)->process($values, 'ipv4', 100, ['collapse' => 3]);
        $this->assertSame(['45.1.1.0/24', '60.0.0.0/24', '80.1.0.0/23', '81.0.0.1', '82.0.0.1'], $result['entries']);
        $this->assertSame(4, $result['stats']['dropped_reserved']);
        $this->assertSame(1, $result['stats']['dropped_allowed']);
        $this->assertSame(2, $result['stats']['collapsed']);

        $exact = app(IpFeedProcessor::class)->process($values, 'ipv4', 100);
        $this->assertContains('60.0.0.0/30', $exact['entries'], 'Without collapsing, merging is exact');
        $this->assertContains('45.1.1.1', $exact['entries']);

        $capped = app(IpFeedProcessor::class)->process($values, 'ipv4', 3, ['collapse' => 3]);
        $this->assertSame(['45.1.1.0/24', '60.0.0.0/24', '80.1.0.0/23'], $capped['entries'], 'The widest ranges are kept first');
        $this->assertSame(2, $capped['stats']['cut']);
    }

    public function test_the_abuseipdb_preset_downloads_the_whole_list_and_processes_it(): void
    {
        $lines = ['#', '# Aggregated Blocklist for AbuseIPDB', '#'];
        for ($i = 0; $i < 300; $i++) {
            $lines[] = sprintf('%d.%d.%d.%d      # XX  AS1 Example', 20 + intdiv($i, 100), $i % 50, 7, $i % 4 + 1);
        }
        Http::fake(['raw.githubusercontent.com/*' => Http::response(implode("\n", $lines)."\n")]);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('admin.policy.blocklists.feeds.store'), ['preset' => 'abuseipdb-30d'])->assertSessionHas('success');
        $source = ListSource::query()->where('preset', 'abuseipdb-30d')->sole();
        $this->assertSame(40000, $source->max_entries);
        $this->assertSame(3, $source->options['collapse']);
        $this->assertGreaterThanOrEqual(40000, $source->firewallList->max_entries);
        $this->assertSame(300, $source->stats['read']);
        $this->assertNotEmpty($source->ip_entries);
        $this->assertTrue(collect($source->ip_entries)->every(fn (string $entry) => str_ends_with($entry, '/24') || ! str_contains($entry, '/')));

        $this->actingAs($admin)->get(route('admin.policy.blocklists.index'))->assertOk()->assertSee('AbuseIPDB: most reported IPs, 30 days')->assertSee('merged into');
        $this->actingAs($admin)->put(route('admin.policy.blocklists.feeds.update', $source), ['enabled' => '1', 'refresh_hours' => 24, 'max_entries' => 40000, 'collapse' => 0]);
        $this->assertSame(0, $source->refresh()->options['collapse']);
    }
}
