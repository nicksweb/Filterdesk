<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\FilterPolicy;
use App\Models\IntegrationConfig;
use App\Models\NetworkType;
use App\Models\User;
use App\Models\WebsiteCheck;
use App\Services\Domains\DomainCategorizer;
use App\Services\Network\MockNetworkProvider;
use App\Services\Policy\AccessExplorer;
use App\Services\Policy\PolicyEngine;
use App\Services\Policy\WhatIf;
use App\Support\Reach;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

/**
 * The access explorer traces why a site is allowed or blocked per network
 * type; what-if shows the effect of a category change beforehand.
 */
class AccessExplorerTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    private PolicyEngine $engine;

    private User $it;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::flush();
        Http::preventStrayRequests();
        $this->engine = $this->installWithPolicies('active');
        FilterPolicy::query()->where('audience', 'student')->update(['provider_reference' => 'sim-filter-students']);
        $this->it = User::factory()->it()->create();
    }

    public function test_it_names_the_entry_that_decides_and_what_the_controller_has_now(): void
    {
        $this->engine->upsertEntry($this->engine->defaultBlockList('student'), 'games.example.org', ['source' => 'manual']);
        $students = NetworkType::byKey('student');

        $result = app(AccessExplorer::class)->explain('games.example.org', $students);

        $this->assertSame('blocked', $result['verdict']);
        $this->assertStringContainsString('a block entry blocks it', $result['reason']);
        $this->assertTrue($result['pending'], 'Not published to the controller yet');
        $this->assertSame('allowed', app(AccessExplorer::class)->explain('games.example.org', NetworkType::byKey('staff'))['verdict'], 'Staff are not affected');

        $this->actingAs($this->it)->get(route('admin.policy.explorer', ['site' => 'games.example.org', 'type' => 'student']))->assertOk()
            ->assertSee('Blocked')->assertSee('games.example.org in Students block')->assertSee('publish the waiting changes');
        // The simulation controller does not apply entries to subdomains, and the trace says so.
        $this->assertSame('allowed', app(AccessExplorer::class)->explain('play.games.example.org', $students)['verdict']);
        $this->actingAs($this->it)->get(route('admin.policy.explorer', ['site' => 'play.games.example.org', 'type' => 'student']))->assertSee('does not apply an entry to subdomains');
    }

    public function test_a_blocked_category_decides_unless_an_allow_entry_overrides_it(): void
    {
        $this->enableCategories(['videos.example.org' => 'Video Streaming']);
        (new MockNetworkProvider)->writeContentFilterCategories('sim-filter-students', ['STREAMING_MEDIA']);
        $students = NetworkType::byKey('student');
        $explorer = app(AccessExplorer::class);

        $blocked = $explorer->explain('videos.example.org', $students);
        $this->assertSame('blocked', $blocked['verdict']);
        $this->assertSame('category', $blocked['policies'][0]['deciding']);

        $this->engine->upsertEntry($this->engine->defaultAllowList('student'), 'videos.example.org', ['source' => 'manual']);
        $this->assertSame('allowed', $explorer->explain('videos.example.org', $students)['verdict']);
    }

    public function test_probe_evidence_and_requester_answers(): void
    {
        $this->engine->upsertEntry($this->engine->defaultBlockList('student'), 'chat.example.org', ['source' => 'manual']);
        WebsiteCheck::query()->create(['user_id' => $this->it->id, 'url' => 'https://chat.example.org', 'hostname' => 'chat.example.org',
            'duration_ms' => 900, 'results' => ['student' => ['audience' => 'student', 'profile' => 'Student Access', 'status' => 'blocked', 'addresses' => []]]]);

        $result = app(AccessExplorer::class)->explain('chat.example.org', NetworkType::byKey('student'));
        $this->assertSame('blocked', $result['evidence']['status']);

        $student = User::factory()->student()->create();
        $this->actingAs($student)->get(route('why', ['site' => 'https://chat.example.org/room']))->assertOk()
            ->assertSee('chat.example.org')->assertSee('The school has blocked this site.')->assertDontSee('Students block', false);
    }

    public function test_what_if_lists_the_approved_sites_a_category_block_would_cut_off(): void
    {
        $categories = fn (string $domain) => [$domain => ['ok' => true, 'categories' => ['Social Networks'], 'risks' => [], 'unifi' => [['id' => 'SOCIAL_NETWORKS', 'label' => 'Social networks', 'blocked_by' => []]]]];
        // Approved with no allow entry (smart approval found nothing to override).
        $this->pendingRequest(['class.example.org'], Reach::types('student'), [
            'status' => RequestStatus::Active, 'approved_domains' => ['class.example.org'], 'applied_scope' => Reach::types('student'), 'domain_categories' => $categories('class.example.org'),
        ]);
        // Approved with an allow entry.
        $kept = $this->pendingRequest(['forum.example.org'], Reach::types('student'), [
            'status' => RequestStatus::Active, 'approved_domains' => ['forum.example.org'], 'applied_scope' => Reach::types('student'), 'domain_categories' => $categories('forum.example.org'),
        ]);
        $this->engine->upsertEntry($this->engine->defaultAllowList('student'), 'forum.example.org', ['source' => 'request', 'website_request_id' => $kept->id]);
        $this->pendingRequest(['new.example.org'], Reach::types('student'), ['domain_categories' => $categories('new.example.org')]);

        $result = app(WhatIf::class)->category(NetworkType::byKey('student'), 'SOCIAL_NETWORKS', block: true);

        $this->assertSame(['class.example.org'], array_column($result['cut_off'], 'domain'));
        $this->assertSame(['forum.example.org'], array_column($result['kept'], 'domain'));
        $this->assertCount(1, $result['open']);
        $this->assertStringContainsString('cut off 1 approved site(s)', $result['summary']);

        $this->actingAs($this->it)->get(route('admin.policy.explorer', ['category' => 'SOCIAL_NETWORKS', 'change' => 'block', 'whatif_type' => 'student']))
            ->assertOk()->assertSee('Would be cut off')->assertSee('class.example.org');
    }

    public function test_each_network_type_has_a_policy_page(): void
    {
        $this->engine->upsertEntry($this->engine->defaultAllowList('student'), 'lesson.example.org', ['source' => 'manual']);

        $this->actingAs($this->it)->get(route('admin.policy.types.show', 'student'))->assertOk()
            ->assertSee('Students')->assertSee('Allow entries')->assertSee('Exam mode')->assertSee('Students allow');
    }

    /** @param array<string, string> $domains */
    private function enableCategories(array $domains): void
    {
        IntegrationConfig::query()->create(['provider' => DomainCategorizer::PROVIDER, 'name' => 'Website categories', 'mode' => 'read_only', 'enabled' => true,
            'configuration' => ['source' => 'radar'], 'credentials' => ['token' => 't']]);
        foreach ($domains as $domain => $category) {
            Cache::put('domain.categories.'.md5($domain), ['ok' => true, 'categories' => [$category], 'risks' => []], 3600);
        }
        Cache::put('unifi.categories.available', ['STREAMING_MEDIA', 'SOCIAL_NETWORKS'], 3600);
    }
}
