<?php

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Models\FilterPolicy;
use App\Models\IntegrationConfig;
use App\Models\NetworkAssignment;
use App\Models\User;
use App\Services\Network\MockNetworkProvider;
use App\Services\Policy\PolicyEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

class PolicyLayerTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    private PolicyEngine $engine;

    private FilterPolicy $students;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::flush();
        $this->engine = $this->installWithPolicies('active');
        $this->students = FilterPolicy::query()->where('audience', 'student')->firstOrFail();
        $this->students->update(['provider_reference' => 'sim-filter-students']);
        NetworkAssignment::query()->create(['provider_network_id' => 'sim-student', 'name' => 'Students', 'vlan' => 20, 'filter_policy_id' => $this->students->id]);

        $siteBlock = $this->engine->defaultBlockList(null);
        $this->engine->upsertEntry($siteBlock, 'tracker.example.net', ['source' => 'feed']);
        $this->engine->upsertEntry($siteBlock, 'approved.example.net', ['source' => 'feed']);
        $this->engine->upsertEntry($siteBlock, 'manual-block.example.org', ['source' => 'manual']);
        $this->engine->upsertEntry($this->engine->defaultAllowList('student'), 'approved.example.net', ['source' => 'manual']);
    }

    /** @return array<string, mixed> */
    private function filter(string $id): array
    {
        return collect((new MockNetworkProvider)->contentFilters())->firstWhere('id', $id);
    }

    private function createLayer(): FilterPolicy
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.policy.policies.layer.store', $this->students), ['name' => 'Students - Blocklists', 'push' => '1'])
            ->assertRedirect()->assertSessionHas('success');

        return $this->students->refresh()->layer;
    }

    public function test_a_layer_takes_the_feeds_on_the_same_networks_and_leaves_out_what_the_parent_allows(): void
    {
        $this->actingAs(User::factory()->it()->create())->post(route('admin.policy.policies.push', $this->students));
        $this->assertContains('tracker.example.net', $this->filter('sim-filter-students')['block']);

        $layer = $this->createLayer();
        $created = $this->filter($layer->provider_reference);

        $this->assertSame(['sim-student'], $created['network_ids']);
        $this->assertSame(['ADULT'], $created['categories'], 'The layer copies the parent categories');
        $this->assertSame('student', $layer->audience);
        $this->assertSame(['tracker.example.net'], $created['block'], 'Feed blocks move to the layer; an allowed host is left out');
        $this->assertSame([], $created['allow']);
        $this->assertSame(['manual-block.example.org'], $this->filter('sim-filter-students')['block']);
        $this->assertContains('approved.example.net', $this->filter('sim-filter-students')['allow']);

        $this->artisan('policy:check-sync')->assertSuccessful();
        $this->assertSame('in_sync', $this->students->refresh()->sync_status);
        $this->assertSame('in_sync', $layer->refresh()->sync_status);

        $this->actingAs(User::factory()->it()->create())->get(route('admin.policy.networks.index'))->assertOk()
            ->assertSee('with layer Students - Blocklists', false)->assertDontSee('In 2 filters', false);
        $this->actingAs(User::factory()->it()->create())->get(route('admin.policy.policies.show', $layer))->assertOk()->assertSee('Layer of Students');
    }

    public function test_a_new_approval_comes_out_of_the_layer_and_categories_follow_the_parent(): void
    {
        $layer = $this->createLayer();
        IntegrationConfig::query()->where('provider', 'mock')->update(['configuration' => ['auto_push' => true, 'controller_authoritative' => true]]);
        $this->engine->upsertEntry($this->engine->defaultAllowList('student'), 'tracker.example.net', ['source' => 'manual']);

        $this->artisan('policy:push')->assertSuccessful();
        $this->assertSame([], $this->filter($layer->provider_reference)['block']);
        $this->assertContains('tracker.example.net', $this->filter('sim-filter-students')['allow']);

        $this->actingAs(User::factory()->admin()->create())->post(route('admin.policy.policies.categories', $this->students), ['categories' => ['ADULT', 'GAMBLING']])
            ->assertSessionHas('success');
        $this->assertSame(['ADULT', 'GAMBLING'], $this->filter($layer->provider_reference)['categories']);
    }

    public function test_a_layer_taken_off_a_network_is_reported_and_a_second_layer_is_refused(): void
    {
        $layer = $this->createLayer();
        $filters = collect((new MockNetworkProvider)->contentFilters())
            ->map(fn (array $filter) => $filter['id'] === $layer->provider_reference ? ['network_ids' => []] + $filter : $filter)->all();
        Cache::forever('mock.content_filters', $filters);

        $this->artisan('policy:check-sync')->assertSuccessful();
        $this->assertSame('drift', $this->students->refresh()->sync_status);
        $this->assertStringContainsString('missing the layer', $this->students->sync_details['summary']);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.policy.policies.layer.store', $this->students), ['name' => 'Another'])
            ->assertSessionHas('error');
        $this->assertSame(1, FilterPolicy::query()->whereNotNull('layer_of_id')->count());
        $this->assertSame(ListKind::Block, $this->engine->ownList($layer, ListKind::Block)->kind);
    }
}
