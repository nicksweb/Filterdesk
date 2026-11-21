<?php

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Models\FilterPolicy;
use App\Models\PolicyList;
use App\Models\User;
use App\Services\Network\MockNetworkProvider;
use App\Services\Policy\PolicyEngine;
use App\Support\Reach;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

/** A hostname already blocked by a list, later allowed by someone who did not know. */
class AllowAfterBlockTest extends TestCase
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
        $this->staff = FilterPolicy::query()->where('audience', 'staff')->firstOrFail();
        $this->staff->update(['provider_reference' => 'sim-filter-staff']);
    }

    /** @return array<string, mixed> */
    private function filter(): array
    {
        return collect((new MockNetworkProvider)->contentFilters())->firstWhere('id', 'sim-filter-staff');
    }

    private function push(): void
    {
        $this->actingAs(User::factory()->it()->create())->post(route('admin.policy.policies.push', $this->staff))->assertSessionHas('success');
    }

    public function test_site_wide_feed_block_then_staff_allow(): void
    {
        $this->engine->upsertEntry($this->engine->defaultBlockList(null), 'blocked.example.com', ['source' => 'feed']);
        $this->push();
        $this->assertContains('blocked.example.com', $this->filter()['block']);

        $this->engine->upsertEntry($this->engine->defaultAllowList('staff'), 'blocked.example.com', ['source' => 'manual']);
        $this->push();
        $this->assertNotContains('blocked.example.com', $this->filter()['block']);
        $this->assertContains('blocked.example.com', $this->filter()['allow']);
    }

    public function test_site_wide_feed_block_then_site_wide_allow(): void
    {
        $this->engine->upsertEntry($this->engine->defaultBlockList(null), 'blocked.example.com', ['source' => 'feed']);
        $this->push();

        $this->engine->upsertEntry($this->engine->defaultAllowList(null), 'blocked.example.com', ['source' => 'manual']);
        $this->push();
        $this->assertNotContains('blocked.example.com', $this->filter()['block']);
        $this->assertContains('blocked.example.com', $this->filter()['allow']);
    }

    public function test_block_imported_from_the_controller_then_approved_request(): void
    {
        // Imports from UniFi land in the policy's own block list.
        $this->engine->upsertEntry($this->engine->ownList($this->staff, ListKind::Block), 'blocked.example.com', ['source' => 'import']);
        $this->push();

        $request = $this->pendingRequest(['blocked.example.com'], Reach::types('staff'));
        $this->engine->grant($request, ['blocked.example.com'], Reach::types('staff'), null);
        $this->push();
        $this->assertNotContains('blocked.example.com', $this->filter()['block']);
        $this->assertContains('blocked.example.com', $this->filter()['allow']);
    }

    public function test_a_deliberate_policy_block_still_beats_an_audience_allow_and_the_admin_is_told(): void
    {
        $this->engine->upsertEntry($this->engine->ownList($this->staff, ListKind::Block), 'blocked.example.com', ['source' => 'manual']);
        $this->engine->upsertEntry($this->engine->defaultBlockList(null), 'feed-blocked.example.com', ['source' => 'feed']);

        $this->actingAs(User::factory()->admin()->create())->post(route('admin.policy.entries.store'), [
            'policy_list_id' => $this->engine->defaultAllowList('staff')->id, 'domains' => "blocked.example.com\nfeed-blocked.example.com", 'include_www' => '0',
        ])->assertSessionHas('success', fn (string $message) => str_contains($message, 'blocked.example.com stay blocked')
            && str_contains($message, 'feed-blocked.example.com were blocked by another list and are now allowed'));

        $this->push();
        $this->assertContains('blocked.example.com', $this->filter()['block']);
        $this->assertNotContains('feed-blocked.example.com', $this->filter()['block']);
    }

    public function test_feed_scoped_to_the_policy_then_staff_allow(): void
    {
        $feedList = PolicyList::query()->create(['name' => 'Policy feed', 'slug' => 'policy-feed', 'kind' => ListKind::Block, 'scope' => 'policy', 'filter_policy_id' => $this->staff->id]);
        $this->engine->upsertEntry($feedList, 'blocked.example.com', ['source' => 'feed']);
        $this->push();

        $this->engine->upsertEntry($this->engine->defaultAllowList('staff'), 'blocked.example.com', ['source' => 'manual']);
        $this->push();
        $this->assertNotContains('blocked.example.com', $this->filter()['block']);
        $this->assertContains('blocked.example.com', $this->filter()['allow']);
    }
}
