<?php

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\ListScope;
use App\Models\FilterPolicy;
use App\Models\FirewallList;
use App\Models\ListSource;
use App\Models\PolicyList;
use App\Models\User;
use App\Services\Network\MockNetworkProvider;
use App\Services\Policy\FeedParser;
use App\Services\Policy\PolicyEngine;
use App\Services\Requests\RequestWorkflow;
use App\Services\SettingsRepository;
use App\Support\Reach;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

class BlocklistTest extends TestCase
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

    private function policy(string $audience): FilterPolicy
    {
        return FilterPolicy::query()->where('audience', $audience)->firstOrFail();
    }

    public function test_a_block_for_one_audience_beats_a_site_wide_allow_and_an_older_approval(): void
    {
        app(RequestWorkflow::class)->approve($this->pendingRequest(['news.example.org'], Reach::types('staff', 'student')), User::factory()->it()->create());
        $this->engine->upsertEntry($this->engine->defaultAllowList(null), 'news.example.org', ['source' => 'manual']);
        $this->travel(1)->minute();
        $staffBlock = PolicyList::query()->where('scope', ListScope::Audience)->where('audience', 'staff')->where('kind', ListKind::Block)->firstOrFail();

        $this->actingAs($this->admin)->post(route('admin.policy.entries.store'), ['policy_list_id' => $staffBlock->id, 'domains' => 'news.example.org'])->assertSessionHas('success');

        $staff = $this->engine->desired($this->policy('staff'));
        $this->assertContains('news.example.org', $staff['block']);
        $this->assertNotContains('news.example.org', $staff['allow']);
        $this->assertSame('block', $staff['resolved']['news.example.org']);
        $this->assertContains('news.example.org', $this->engine->desired($this->policy('student'))['allow'], 'Students are not affected');

        $this->travel(1)->minute();
        $this->engine->upsertEntry($this->engine->defaultAllowList('staff'), 'news2.example.org', ['source' => 'manual']);
        $siteBlock = PolicyList::query()->where('scope', ListScope::Site)->where('kind', ListKind::Block)->firstOrFail();
        // Without two-person approval, so the block goes live straight away (TwoPersonApprovalTest covers proposals).
        app(SettingsRepository::class)->set('approval.two_person_site_wide', false);
        $this->actingAs($this->admin)->post(route('admin.policy.entries.store'), ['policy_list_id' => $siteBlock->id, 'domains' => 'news2.example.org'])
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'stay allowed'));
    }

    public function test_adding_a_bare_domain_or_www_adds_both_when_asked(): void
    {
        $siteBlock = PolicyList::query()->where('scope', ListScope::Site)->where('kind', ListKind::Block)->firstOrFail();

        $this->actingAs($this->admin)->post(route('admin.policy.entries.store'), ['policy_list_id' => $siteBlock->id, 'domains' => "www.games.example\nexample.co.uk\ncdn.files.example.net", 'include_www' => '1']);

        $this->assertEqualsCanonicalizing(
            ['www.games.example', 'games.example', 'example.co.uk', 'www.example.co.uk', 'cdn.files.example.net'],
            $siteBlock->entries()->pluck('domain')->all(),
        );
    }

    public function test_a_domain_feed_fills_its_own_list_syncs_on_refresh_and_never_overrides_approvals(): void
    {
        $body = "# Title: test\n! adblock comment\nbadvpn.example\n*.proxy.example\n||doh.example^\n0.0.0.0 hosts.example\n@@||allowed.example^\ncom\n\nfriendly.example # inline\n";
        Http::fake(['raw.githubusercontent.com/*' => Http::sequence()->push($body)->push("badvpn.example\nnew.example\n")]);
        app(RequestWorkflow::class)->approve($this->pendingRequest(['friendly.example'], Reach::types('staff', 'student')), User::factory()->it()->create());

        $this->actingAs($this->admin)->post(route('admin.policy.blocklists.feeds.store'), ['preset' => 'hagezi-bypass', 'scope' => 'site', 'kind' => 'block'])
            ->assertSessionHas('success');

        $source = ListSource::query()->firstOrFail();
        $this->assertEqualsCanonicalizing(['badvpn.example', 'proxy.example', 'doh.example', 'hosts.example', 'friendly.example'], $source->policyList->entries()->pluck('domain')->all());
        $student = $this->engine->desired($this->policy('student'));
        $this->assertContains('badvpn.example', $student['block']);
        $this->assertContains('friendly.example', $student['allow'], 'An approved request overrides a site-wide feed');

        $this->actingAs($this->admin)->post(route('admin.policy.entries.store'), ['policy_list_id' => $source->policy_list_id, 'domains' => 'x.example'])->assertSessionHasErrors('policy_list_id');

        $this->actingAs($this->admin)->post(route('admin.policy.blocklists.feeds.refresh', $source))->assertSessionHas('success');
        $this->assertEqualsCanonicalizing(['badvpn.example', 'new.example'], $source->policyList->entries()->pluck('domain')->all());

        $this->actingAs($this->admin)->delete(route('admin.policy.blocklists.feeds.destroy', $source));
        $this->assertNotContains('badvpn.example', $this->engine->desired($this->policy('student'))['block']);
    }

    public function test_an_ip_feed_keeps_a_controller_ip_group_in_step_and_respects_the_limit(): void
    {
        Http::fake(['raw.githubusercontent.com/*' => Http::response("# DoH IPs\n1.1.1.1\n8.8.8.8\n2606:4700::1111\n9.9.9.0/24\nnot-an-ip\n10.0.0.1\n")]);

        $this->actingAs($this->admin)->post(route('admin.policy.blocklists.feeds.store'), ['preset' => 'hagezi-doh-ips'])->assertSessionHas('success');
        $list = FirewallList::query()->firstOrFail();
        $this->assertEqualsCanonicalizing(['1.1.1.1', '8.8.8.8', '9.9.9.0/24', '10.0.0.1'], $list->desiredEntries()['entries']);

        $this->actingAs($this->admin)->put(route('admin.policy.blocklists.firewall.update', $list), [
            'name' => 'DoH servers', 'manual_entries' => "203.0.113.9\n198.51.100.0/24", 'provider_reference' => 'sim-group-1', 'max_entries' => 5, 'enabled' => '1',
        ])->assertSessionHas('success');
        $this->actingAs($this->admin)->post(route('admin.policy.blocklists.firewall.push', $list))->assertSessionHas('success');

        $group = (new MockNetworkProvider)->rawIpGroups()['sim-group-1'];
        $this->assertCount(5, $group['items']);
        $this->assertSame('truncated', $list->refresh()->last_push_status);
        $this->assertSame('Block listed IPs', $list->referenced_by[0]['name']);

        $this->actingAs($this->admin)->put(route('admin.policy.blocklists.firewall.update', $list), ['name' => 'DoH', 'manual_entries' => 'nonsense', 'max_entries' => 5])
            ->assertSessionHasErrors('manual_entries');
    }

    public function test_it_staff_cannot_manage_blocklists(): void
    {
        $it = User::factory()->it()->create();
        $this->actingAs($it)->get(route('admin.policy.blocklists.index'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.policy.blocklists.index'))->assertOk()->assertSee('DNS bypass');
    }

    public function test_every_preset_option_on_the_page_submits_a_valid_preset(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.policy.blocklists.index'))->assertOk()
            ->assertSee('hagezi DNS Blocklists')->assertSee('GPL-3.0')->getContent();
        preg_match('/<select name="preset">(.*?)<\/select>/s', $html, $select);
        preg_match_all('/<option value="([^"]+)"/', $select[1], $values);

        $this->assertEqualsCanonicalizing(array_keys(config('blocklists.presets')), $values[1]);
    }

    public function test_feed_parser_handles_common_formats(): void
    {
        $parser = app(FeedParser::class);

        $this->assertSame('ads.example', $parser->domain('||ads.example^$third-party'));
        $this->assertSame('track.example', $parser->domain('127.0.0.1 track.example'));
        $this->assertNull($parser->domain('@@||ok.example^'));
        $this->assertNull($parser->domain('co.uk'));
        $this->assertSame('192.0.2.0/24', $parser->ip('192.0.2.0/24 ; comment', 'ipv4'));
        $this->assertSame('192.0.2.1', $parser->ip('192.0.2.1/32', 'ipv4'));
        $this->assertNull($parser->ip('192.0.2.1/40', 'ipv4'));
        $this->assertSame('2001:db8::1', $parser->ip('2001:0db8::0001', 'ipv6'));
    }
}
