<?php

namespace Tests\Feature;

use App\Models\FirewallList;
use App\Models\NetworkProfile;
use App\Models\NetworkType;
use App\Models\User;
use App\Services\Policy\FirewallAllowList;
use App\Services\Policy\FirewallEvaluator;
use App\Services\Policy\FirewallListPusher;
use App\Services\Policy\SiteFirewallCheck;
use App\Support\Reach;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

/**
 * Sites blocked by the UniFi firewall rather than the content filter: the
 * rules are evaluated against a site's addresses, and a firewall allow list
 * lets addresses through with Allow rules kept above the block rules, for
 * every zone or chosen ones.
 */
class FirewallAllowListTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::flush();
        $this->installWithPolicies('active');
        $this->admin = User::factory()->admin()->create();
        NetworkProfile::query()->where('key', 'student')->update(['unifi_network_id' => 'sim-student', 'enabled' => true]);
        NetworkProfile::query()->where('key', 'staff')->update(['unifi_network_id' => 'sim-staff', 'enabled' => true]);

        // UniFi as in the report: a VPS list holding a /15, blocked Internal → External; a disabled
        // rule for a narrower list; and a whitelist no rule uses.
        Cache::forever('mock.ip_groups', [
            'sim-vps' => ['id' => 'sim-vps', 'name' => 'Zenlayer VPS', 'family' => 'ipv4', 'items' => ['45.76.0.0/15', '198.18.0.0/15']],
            'sim-vultr' => ['id' => 'sim-vultr', 'name' => 'Vultr IPs', 'family' => 'ipv4', 'items' => ['45.76.112.0/20']],
            'sim-white' => ['id' => 'sim-white', 'name' => 'Whitelist VPS IPs', 'family' => 'ipv4', 'items' => ['45.76.120.225']],
        ]);
        Cache::forever('mock.firewall_rules', [
            'rule-vps' => ['id' => 'rule-vps', 'name' => 'Block VPS Provider ASNs', 'group' => 'sim-vps', 'source' => 'zone-internal', 'destination' => 'zone-external', 'action' => 'BLOCK'],
            'rule-vultr' => ['id' => 'rule-vultr', 'name' => 'Block Vultr IPs', 'group' => 'sim-vultr', 'source' => 'zone-internal', 'destination' => 'zone-external', 'action' => 'BLOCK', 'enabled' => false],
            'rule-geo' => ['id' => 'rule-geo', 'name' => 'Block by region', 'source' => 'zone-internal', 'destination' => 'zone-external', 'action' => 'BLOCK', 'match' => ['kind' => 'unknown', 'label' => 'region']],
        ]);
        Http::fake(fn (HttpRequest $request) => str_ends_with($request->url(), '/resolve')
            ? Http::response(['host' => $request['host'], 'addresses' => Cache::get('test.dns.'.$request['host']) ?? match ($request['host']) {
                'aurora.example.edu', 'www.aurora.example.edu' => ['45.76.120.225'], 'cdn-site.example' => ['104.16.1.1'], default => [],
            }, 'resolver' => '10.0.20.1', 'blocked' => false, 'connect' => $request['connect'] ? ['45.76.120.225' => 'timeout'] : []])
            : Http::response([], 404));
    }

    private function allowList(array $attributes = []): FirewallList
    {
        return FirewallList::query()->create(['name' => 'Firewall allow', 'family' => 'ipv4', 'kind' => 'allow', 'all_zones' => true, 'max_entries' => 2000] + $attributes);
    }

    public function test_the_firewall_rule_that_blocks_an_address_is_found_in_unifi_order(): void
    {
        $evaluator = app(FirewallEvaluator::class);
        $result = $evaluator->evaluate('45.76.120.225', 'zone-internal');

        $this->assertSame('block', $result['decision']);
        $this->assertSame('Block VPS Provider ASNs', $result['rule']['name']);
        $this->assertStringContainsString('Zenlayer VPS contains it via 45.76.0.0/15', $result['via']);
        $this->assertSame(['Block by region (region)'], $result['skipped'], 'A rule that matches by region cannot be checked against an address');
        $this->assertSame('default', $evaluator->evaluate('93.184.216.34', 'zone-internal')['decision']);
        $this->assertSame('default', $evaluator->evaluate('45.76.120.225', 'zone-hotspot')['decision'], 'Only the zone\'s own rules count');
        $this->assertSame(['zone-internal', 'Internal', 'int-student'], array_values($evaluator->zoneForProfile(NetworkProfile::query()->where('key', 'student')->sole())));
        $this->assertSame(['Whitelist VPS IPs'], collect($evaluator->unusedGroups())->pluck('name')->all(), 'An IP list no rule uses is pointed out');
    }

    public function test_an_allow_list_takes_addresses_and_domains_and_refuses_wide_or_shared_ones_without_a_reason(): void
    {
        $list = $this->allowList();
        $allow = app(FirewallAllowList::class);

        $result = $allow->add($list, ['aurora.example.edu', '203.0.113.0/28', '10.0.0.0/12', '198.51.100.0/22', 'cdn-site.example'], $this->admin, 'Partner portal', 'REQ-1', now()->addDay()->toIso8601String());
        $this->assertSame(['aurora.example.edu (45.76.120.225)', '203.0.113.0/28'], $result['added']);
        $this->assertCount(3, $result['refused']);
        $this->assertStringContainsString('Too wide', $result['refused'][0]);
        $this->assertStringContainsString('Give a reason', $result['refused'][1]);
        $this->assertStringContainsString('Cloudflare', $result['refused'][2]);

        $this->assertSame(['198.51.100.0/22'], $allow->add($list, ['198.51.100.0/22'], $this->admin, reason: 'Our ISP range')['added']);
        $this->assertSame(['198.51.100.0/22', '203.0.113.0/28', '45.76.120.225'], $list->refresh()->desiredEntries()['entries']);

        // Expired entries come out on the hourly refresh.
        $entries = $list->allow_entries;
        $entries[1]['expires_at'] = now()->subMinute()->toIso8601String();
        $list->forceFill(['allow_entries' => $entries])->save();
        $this->assertTrue($allow->refresh($list->refresh()));
        $this->assertNotContains('203.0.113.0/28', $list->refresh()->desiredEntries()['entries']);
    }

    public function test_pushing_an_allow_list_creates_allow_rules_for_every_zone_above_the_block_rules(): void
    {
        $list = $this->allowList();
        app(FirewallAllowList::class)->add($list, ['45.76.120.225'], $this->admin);
        $result = app(FirewallListPusher::class)->push($list->refresh(), $this->admin, force: true);
        $this->assertTrue($result['ok'], $result['message']);

        $rules = collect(Cache::get('mock.firewall_rules'))->where('action', 'ALLOW');
        $this->assertEqualsCanonicalizing(['Allow Firewall allow (Internal)', 'Allow Firewall allow (Hotspot)', 'Allow Firewall allow (Vpn)'], $rules->pluck('name')->all());
        $this->assertSame($rules->pluck('id')->sort()->values()->all(), collect(Cache::get('mock.firewall_first'))->take(3)->sort()->values()->all(), 'The Allow rules go to the top');

        // A block list pushed afterwards with "put first" stays below the allow rules.
        $block = FirewallList::query()->create(['name' => 'Bad IPs', 'family' => 'ipv4', 'manual_entries' => ['45.76.120.225'], 'rule_zones' => ['zone-internal'], 'rules_first' => true, 'max_entries' => 100]);
        app(FirewallListPusher::class)->push($block, $this->admin, force: true);
        $order = Cache::get('mock.firewall_first');
        $blockRule = collect(Cache::get('mock.firewall_rules'))->firstWhere('name', 'Block Bad IPs (Internal)')['id'];
        $this->assertLessThan(array_search($blockRule, $order, true), array_search($rules->firstWhere('name', 'Allow Firewall allow (Internal)')['id'], $order, true));

        $evaluation = app(FirewallEvaluator::class)->evaluate('45.76.120.225', 'zone-internal');
        $this->assertSame('allow', $evaluation['decision']);
        $this->assertContains('Block VPS Provider ASNs', collect($evaluation['blocks'])->pluck('name')->all());
        $this->assertSame(['Firewall allow'], $evaluation['allow_lists']);

        $this->actingAs($this->admin)->get(route('admin.policy.firewall-allow.index'))->assertOk()
            ->assertSee('Block VPS Provider ASNs')->assertSee('Allow rules in UniFi')->assertSee('Whitelist VPS IPs');
    }

    public function test_chosen_zones_only_and_zones_left_uncovered_are_shown(): void
    {
        $list = $this->allowList();
        $this->actingAs($this->admin)->post(route('admin.policy.firewall-allow.zones', $list), ['scope' => 'chosen', 'zones' => ['zone-hotspot']])->assertSessionHas('success');
        $this->assertFalse($list->refresh()->all_zones);
        $this->assertSame(['zone-hotspot'], $list->rule_zones);
        app(FirewallAllowList::class)->add($list, ['45.76.120.225'], $this->admin);
        $this->actingAs($this->admin)->get(route('admin.policy.firewall-allow.index'))->assertOk()->assertSee('Zones with block rules this list doesn\'t cover: Internal', false);
    }

    public function test_adopting_a_unifi_list_brings_its_addresses_in_and_the_plain_text_url_serves_them(): void
    {
        $list = $this->allowList();
        $this->actingAs($this->admin)->post(route('admin.policy.firewall-allow.adopt', $list), ['group' => 'sim-white'])->assertSessionHas('success');
        $this->assertSame('sim-white', $list->refresh()->provider_reference);
        $this->assertSame(['45.76.120.225'], $list->desiredEntries()['entries']);

        $this->actingAs($this->admin)->post(route('admin.policy.firewall-allow.feed', $list), ['on' => '1']);
        $token = $list->refresh()->feed_token;
        $this->get(route('firewall.allow.plain', $token))->assertOk()->assertSeeText('45.76.120.225')->assertHeader('Content-Type', 'text/plain; charset=utf-8');
        $this->get('/feeds/ip/'.str_repeat('a', 40).'/allow.txt')->assertNotFound();
        $this->actingAs(User::factory()->it()->create())->get(route('admin.policy.firewall-allow.index'))->assertForbidden();
    }

    public function test_quick_allow_says_the_firewall_blocks_it_and_can_add_the_address(): void
    {
        $list = $this->allowList();
        $check = app(SiteFirewallCheck::class)->check(['aurora.example.edu'], Reach::types('student'));
        $this->assertSame('firewall', $check['hosts']['aurora.example.edu']['verdict']);

        $input = ['domains' => 'aurora.example.edu', 'kind' => 'allow', 'types' => ['student'], 'when' => 'all', 'include_www' => '0'];
        $this->actingAs($this->admin)->post(route('admin.policy.quick.preview'), $input)->assertOk()
            ->assertSee('is blocked by the firewall rule Block VPS Provider ASNs')->assertSee('Also add aurora.example.edu to the firewall allow list');

        $this->actingAs($this->admin)->post(route('admin.policy.quick.apply'), $input + ['firewall_hosts' => ['aurora.example.edu'], 'firewall_list_id' => $list->id])
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'Added to the firewall allow list Firewall allow'));
        $this->assertSame(['45.76.120.225'], $list->refresh()->desiredEntries()['entries']);

        // Quick block the other way: it says the address is on the allow list and offers to take it off.
        $block = ['domains' => 'aurora.example.edu', 'kind' => 'block', 'types' => ['student'], 'when' => 'all', 'include_www' => '0'];
        $this->actingAs($this->admin)->post(route('admin.policy.quick.preview'), $block)->assertOk()->assertSee('can be bypassed by visiting the address directly');
        $this->actingAs($this->admin)->post(route('admin.policy.quick.apply'), $block + ['firewall_unallow' => [$list->id.':aurora.example.edu']]);
        $this->assertSame([], $list->refresh()->desiredEntries()['entries']);
    }

    public function test_approving_a_request_can_also_allow_its_address_at_the_firewall(): void
    {
        $list = $this->allowList();
        $request = $this->pendingRequest(['aurora.example.edu'], Reach::types('student'), ['requested_until' => now()->addWeek()]);
        $this->actingAs($this->admin)->get(route('requests.show', $request))->assertOk()->assertSee('is blocked by the firewall rule Block VPS Provider ASNs');

        $this->actingAs($this->admin)->post(route('it.requests.action', $request), ['action' => 'approve', 'domains' => ['aurora.example.edu'], 'scope' => ['student'], 'smart' => '0',
            'firewall_hosts' => ['aurora.example.edu'], 'firewall_list_id' => $list->id])->assertSessionHas('success', fn (string $message) => str_contains($message, 'firewall allow list'));
        $entry = $list->refresh()->allow_entries[0];
        $this->assertSame($request->reference, $entry['request']);
        $this->assertNotNull($entry['expires_at'], 'The firewall entry ends with the access');
    }

    public function test_the_access_explorer_names_the_firewall_rule(): void
    {
        $this->actingAs($this->admin)->get(route('admin.policy.explorer', ['site' => 'aurora.example.edu', 'type' => NetworkType::byKey('student')?->key ?? 'student']))->assertOk()
            ->assertSee('Blocked by the UniFi firewall, not the content filter');
    }

    public function test_rules_for_some_sources_other_protocols_or_invalid_traffic_do_not_decide(): void
    {
        $rules = Cache::get('mock.firewall_rules');
        Cache::forever('mock.firewall_rules', [
            'rule-servers' => ['id' => 'rule-servers', 'name' => 'Block 172.16.190.0/25 Internet Access', 'source' => 'zone-internal', 'destination' => 'zone-external', 'action' => 'BLOCK',
                'match' => ['kind' => 'any', 'ports' => null], 'source_match' => ['kind' => 'addresses', 'addresses' => ['172.16.190.0/25'], 'label' => '172.16.190.0/25']],
            'rule-invalid' => ['id' => 'rule-invalid', 'name' => 'Block Invalid Traffic', 'source' => 'zone-internal', 'destination' => 'zone-external', 'action' => 'BLOCK', 'match' => ['kind' => 'any', 'ports' => null], 'states' => ['INVALID']],
            'rule-quic' => ['id' => 'rule-quic', 'name' => 'Block QUIC', 'source' => 'zone-internal', 'destination' => 'zone-external', 'action' => 'BLOCK', 'match' => ['kind' => 'any', 'ports' => [[443, 443]]], 'protocol' => 'udp'],
            'rule-staff' => ['id' => 'rule-staff', 'name' => 'Allow staff anywhere', 'source' => 'zone-internal', 'destination' => 'zone-external', 'action' => 'ALLOW',
                'match' => ['kind' => 'any', 'ports' => null], 'source_match' => ['kind' => 'networks', 'networks' => ['int-staff'], 'opposite' => false]],
        ] + $rules);

        $evaluator = app(FirewallEvaluator::class);
        $student = $evaluator->evaluate('45.76.120.225', 'zone-internal', networkId: 'int-student');
        $this->assertSame('Block VPS Provider ASNs', $student['rule']['name'], 'Only rules for every device on the network decide');
        $this->assertSame(['Block 172.16.190.0/25 Internet Access', 'Allow staff anywhere'], collect($student['source_scoped'])->pluck('name')->all());
        $this->assertNotContains('Block QUIC', collect($student['blocks'])->pluck('name')->all());
        $this->assertNotContains('Block Invalid Traffic', collect($student['blocks'])->pluck('name')->all());
        $this->assertSame('allow', $evaluator->evaluate('45.76.120.225', 'zone-internal', networkId: 'int-staff')['decision'], 'A rule for the staff network applies to it');

        // A zone-wide Allow rule would open the address for the servers network too, and the page says so.
        $list = $this->allowList();
        app(FirewallAllowList::class)->add($list, ['45.76.120.225'], $this->admin);
        $this->actingAs($this->admin)->get(route('admin.policy.firewall-allow.index'))->assertOk()->assertSee('Also opens it for')->assertSee('which Block 172.16.190.0/25 Internet Access otherwise blocks');
    }

    public function test_allow_rules_can_sit_just_above_the_matching_block_and_be_limited_to_some_networks(): void
    {
        $list = $this->allowList();
        app(FirewallAllowList::class)->add($list, ['45.76.120.225'], $this->admin);
        $this->actingAs($this->admin)->post(route('admin.policy.firewall-allow.zones', $list), ['scope' => 'all', 'source' => 'networks', 'networks' => ['int-student'], 'placement' => 'above_blocks']);
        $this->assertSame(['int-student'], $list->refresh()->source_networks);
        app(FirewallListPusher::class)->push($list, $this->admin, force: true);

        $allow = collect(Cache::get('mock.firewall_rules'))->where('action', 'ALLOW')->values();
        $this->assertCount(1, $allow, 'Only the Internal zone has a ticked network');
        $this->assertSame(['int-student'], $allow[0]['source_match']['networks']);
        $order = Cache::get('mock.firewall_first');
        $this->assertSame(array_search('rule-vps', $order, true) - 1, array_search($allow[0]['id'], $order, true), 'Directly above the block rule that matches');

        $this->actingAs($this->admin)->get(route('admin.policy.firewall-allow.index'))->assertOk()
            ->assertSee('Rule in UniFi')->assertSee('Student Access')->assertSee('before Block VPS Provider ASNs');
    }

    public function test_www_and_entries_for_the_same_address_are_one_row_and_the_nightly_check_flags_changes(): void
    {
        $list = $this->allowList();
        $allow = app(FirewallAllowList::class);
        $result = $allow->add($list, ['aurora.example.edu', 'www.aurora.example.edu'], $this->admin);
        $this->assertSame(['aurora.example.edu (and www.) (45.76.120.225)'], $result['added']);
        $this->assertSame(['www.aurora.example.edu'], $list->refresh()->allow_entries[0]['aliases']);
        $this->assertStringContainsString('already on the list', $allow->add($list, ['45.76.120.225'], $this->admin)['warnings'][0]);
        $this->assertSame(['45.76.120.225'], $list->refresh()->desiredEntries()['entries']);
        $allow->add($list, ['203.0.113.5'], $this->admin);

        // The site moves, and an address no rule blocks is flagged as maybe not needed.
        Cache::forever('test.dns.aurora.example.edu', ['45.76.120.230']);
        Cache::forever('test.dns.www.aurora.example.edu', ['45.76.120.230']);
        $flags = $allow->verify($list->refresh());
        $this->assertTrue(collect($flags)->contains(fn ($flag) => str_contains($flag, 'now resolves to 45.76.120.230')));
        $this->assertTrue(collect($flags)->contains(fn ($flag) => str_contains($flag, 'No firewall rule blocks 203.0.113.5')));
        $this->assertSame([], $allow->verify($list->refresh()), 'Only new flags are reported again');

        Cache::forever('test.dns.aurora.example.edu', []);
        Cache::forever('test.dns.www.aurora.example.edu', []);
        $this->assertTrue(collect($allow->verify($list->refresh()))->contains(fn ($flag) => str_contains($flag, 'no longer resolves')));
        $this->actingAs($this->admin)->get(route('admin.policy.firewall-allow.index'))->assertOk()->assertSee('To check')->assertSee('no longer resolves');
        $this->artisan('firewall:verify-allow')->assertSuccessful();
    }

    public function test_a_network_whose_zone_cannot_be_found_is_named_instead_of_reported_as_no_rule(): void
    {
        NetworkProfile::query()->where('key', 'student')->update(['unifi_network_id' => 'sim-nowhere']);
        $profile = NetworkProfile::query()->where('key', 'student')->sole();
        $this->assertStringContainsString("Couldn't work out which firewall zone", app(FirewallEvaluator::class)->zoneProblem($profile));

        $check = app(SiteFirewallCheck::class)->check(['aurora.example.edu'], Reach::types('student'));
        $this->assertStringContainsString("UniFi's firewall rules weren't checked for Student Access", $check['hosts']['aurora.example.edu']['summary']);
        $this->assertStringNotContainsString('matches it', $check['hosts']['aurora.example.edu']['summary']);
    }
}
