<?php

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\RequestStatus;
use App\Models\FilterPolicy;
use App\Models\IntegrationConfig;
use App\Models\PolicyEntry;
use App\Models\PolicyList;
use App\Models\User;
use App\Services\Domains\DomainCategorizer;
use App\Services\Network\MockNetworkProvider;
use App\Services\Policy\AccessPlanner;
use App\Services\Policy\ControllerSync;
use App\Services\Policy\PolicyEngine;
use App\Support\Reach;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

class SmartApprovalTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    private PolicyEngine $engine;

    private FilterPolicy $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::flush();
        $this->engine = $this->installWithPolicies('active');
        IntegrationConfig::query()->where('provider', 'mock')->update(['configuration' => ['auto_push' => true, 'controller_authoritative' => true]]);
        IntegrationConfig::query()->create(['provider' => DomainCategorizer::PROVIDER, 'name' => 'Website categories', 'mode' => 'read_only', 'enabled' => true,
            'configuration' => ['source' => 'radar'], 'credentials' => ['token' => 't']]);
        $this->staff = FilterPolicy::query()->where('audience', 'staff')->sole();
        $this->staff->update(['provider_reference' => 'sim-filter-staff']);
        FilterPolicy::query()->where('audience', 'student')->update(['enabled' => false]);
        (new MockNetworkProvider)->writeContentFilterCategories('sim-filter-staff', ['GAMBLING']);
        app(ControllerSync::class)->state(true);

        Http::fake([
            'api.cloudflare.com/*/church.example' => Http::response(['result' => ['details_0' => ['categories' => [['name' => 'Religion']]]]]),
            'api.cloudflare.com/*/casino.example' => Http::response(['result' => ['details_0' => ['categories' => [['name' => 'Gambling']]]]]),
            'api.cloudflare.com/*/news.example' => Http::response(['result' => ['details_0' => ['categories' => [['name' => 'News']]]]]),
            'api.cloudflare.com/*/mystery.example' => Http::response(['result' => ['details_0' => ['categories' => []]]]),
        ]);
    }

    public function test_smart_approval_removes_a_deliberate_block_adds_allows_only_where_needed_and_settles(): void
    {
        $admin = User::factory()->admin()->create();
        $staffBlock = $this->engine->defaultBlockList('staff');
        $this->engine->upsertEntry($staffBlock, 'church.example', ['source' => 'manual']);
        $this->actingAs($admin)->post(route('admin.policy.policies.push', $this->staff));

        $request = $this->pendingRequest(['church.example', 'casino.example', 'news.example', 'mystery.example'], Reach::types('staff'));
        $this->actingAs($admin)->get(route('requests.show', $request))->assertOk()
            ->assertSee('Only add allow entries where needed')->assertSee('Remove block')->assertSee('Nothing needed')->assertSee('Gambling is a blocked category in Staff', false);

        $this->actingAs($admin)->post(route('it.requests.action', $request), ['action' => 'approve', 'smart' => '1', 'domains' => ['church.example', 'casino.example', 'news.example', 'mystery.example']])
            ->assertSessionHas('success');

        $request->refresh();
        $this->assertSame(['casino.example' => 'allow', 'church.example' => 'unblock', 'mystery.example' => 'allow', 'news.example' => 'none'],
            collect($request->access_plan)->map(fn (array $step) => $step['action'])->sortKeys()->all());
        $this->assertFalse(PolicyEntry::query()->where('policy_list_id', $staffBlock->id)->where('domain', 'church.example')->exists(), 'The block was removed');
        $allowed = PolicyEntry::query()->where('website_request_id', $request->id)->whereHas('policyList', fn ($q) => $q->where('kind', ListKind::Allow))->pluck('domain')->sort()->values()->all();
        $this->assertSame(['casino.example', 'mystery.example'], $allowed, 'Allow entries only where a category or an unknown category needs one');

        $filter = collect((new MockNetworkProvider)->contentFilters())->firstWhere('id', 'sim-filter-staff');
        $this->assertNotContains('church.example', $filter['block']);
        $this->assertNotContains('church.example', $filter['allow']);
        $this->assertSame(RequestStatus::Active, $request->status, 'Settles once listed hosts are allowed and the rest are not blocked');
        $this->assertStringContainsString('church.example: removed from Staff block', $request->events()->where('type', 'approved')->value('message'));
    }

    public function test_feeds_lists_outside_the_approval_and_timed_access_keep_allow_entries(): void
    {
        $this->engine->upsertEntry($this->engine->defaultBlockList(null), 'news.example', ['source' => 'manual']);
        $plan = app(AccessPlanner::class)->plan(['news.example'], Reach::types('staff'), timed: false);
        $this->assertSame('allow', $plan['news.example']['action'], 'A site-wide block also covers other networks');

        $feedList = PolicyList::query()->create(['name' => 'Feed', 'kind' => ListKind::Block, 'scope' => 'audience', 'audience' => 'staff']);
        $this->engine->upsertEntry($feedList, 'church.example', ['source' => 'feed']);
        $this->assertSame('allow', app(AccessPlanner::class)->plan(['church.example'], Reach::types('staff'), timed: false)['church.example']['action']);

        $this->assertSame('allow', app(AccessPlanner::class)->plan(['news.example'], Reach::siteWide(), timed: true)['news.example']['action']);
        $this->assertSame('unblock', app(AccessPlanner::class)->plan(['news.example'], Reach::siteWide(), timed: false)['news.example']['action']);
    }

    public function test_a_block_imported_from_unifi_is_removed_from_the_controller_instead_of_overridden(): void
    {
        $admin = User::factory()->admin()->create();
        // As after "Bring everything in": the block was made in UniFi and imported into the policy's own list.
        (new MockNetworkProvider)->writeContentFilterLists('sim-filter-staff', [], ['church.example', 'www.church.example']);
        $own = $this->engine->ownList($this->staff, ListKind::Block);
        foreach (['church.example', 'www.church.example'] as $domain) {
            $this->engine->upsertEntry($own, $domain, ['source' => 'import']);
        }
        $this->artisan('policy:check-sync')->assertSuccessful();
        Http::fake(['api.cloudflare.com/*/www.church.example' => Http::response(['result' => ['details_0' => ['categories' => [['name' => 'Religion']]]]])]);

        $request = $this->pendingRequest(['church.example', 'www.church.example'], Reach::types('staff'));
        $this->actingAs($admin)->post(route('it.requests.action', $request), ['action' => 'approve', 'smart' => '1', 'domains' => ['church.example', 'www.church.example']])->assertSessionHas('success');

        $request->refresh();
        $this->assertSame(['unblock', 'unblock'], array_values(collect($request->access_plan)->map(fn (array $step) => $step['action'])->all()));
        $filter = collect((new MockNetworkProvider)->contentFilters())->firstWhere('id', 'sim-filter-staff');
        $this->assertSame([], $filter['block'], 'The UniFi block was removed');
        $this->assertSame([], $filter['allow'], 'No allow entry was needed');
        $this->assertSame(RequestStatus::Active, $request->status);

        $this->artisan('policy:check-sync')->assertSuccessful();
        $this->assertFalse(PolicyEntry::query()->where('domain', 'church.example')->whereHas('policyList', fn ($q) => $q->where('kind', ListKind::Block))->exists(), 'It is not imported back');
    }

    public function test_a_category_blocked_after_a_smart_approval_gets_an_allow_entry_without_new_lookups(): void
    {
        $admin = User::factory()->admin()->create();
        $this->engine->upsertEntry($this->engine->defaultBlockList('staff'), 'church.example', ['source' => 'manual']);
        $request = $this->pendingRequest(['church.example', 'news.example'], Reach::types('staff'));
        $this->actingAs($admin)->post(route('it.requests.action', $request), ['action' => 'approve', 'smart' => '1', 'domains' => ['church.example', 'news.example']]);
        $this->assertSame('RELIGION', $request->refresh()->access_plan['church.example']['unifi'][0]);
        $lookups = count(Http::recorded());

        $this->actingAs($admin)->post(route('admin.policy.policies.categories', $this->staff), ['categories' => ['GAMBLING', 'RELIGION']])->assertSessionHas('success');

        $request->refresh();
        $this->assertSame('allow', $request->access_plan['church.example']['action']);
        $this->assertSame('none', $request->access_plan['news.example']['action'], 'News is still not blocked');
        $this->assertContains('church.example', collect((new MockNetworkProvider)->contentFilters())->firstWhere('id', 'sim-filter-staff')['allow']);
        $this->assertTrue($request->events()->where('type', 'category_guard')->exists());
        $this->assertSame($lookups, count(Http::recorded()), 'The guard uses the stored categories, not new lookups');
    }

    public function test_category_lookups_stop_at_the_hourly_limit(): void
    {
        RateLimiter::increment('domain-categories', 3600, DomainCategorizer::HOURLY_LIMIT);
        $result = app(DomainCategorizer::class)->lookup('fresh.example');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('hourly limit', $result['message']);
        $this->assertSame('allow', app(AccessPlanner::class)->plan(['fresh.example'], Reach::types('staff'), timed: false)['fresh.example']['action'], 'Falls back to an allow entry');
    }
}
