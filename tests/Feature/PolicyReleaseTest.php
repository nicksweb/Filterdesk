<?php

namespace Tests\Feature;

use App\Models\FilterPolicy;
use App\Models\PolicyRelease;
use App\Models\User;
use App\Services\Network\MockNetworkProvider;
use App\Services\Policy\ControllerPusher;
use App\Services\Policy\PolicyEngine;
use App\Services\Policy\PolicyPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

/**
 * Every controller write belongs to a numbered policy version that can be
 * previewed beforehand and rolled back as a whole.
 */
class PolicyReleaseTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    private PolicyEngine $engine;

    private FilterPolicy $students;

    private User $it;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::flush();
        $this->engine = $this->installWithPolicies('active');
        $this->students = FilterPolicy::query()->where('audience', 'student')->whereNull('layer_of_id')->firstOrFail();
        $this->students->update(['provider_reference' => 'sim-filter-students']);
        $this->it = User::factory()->it()->create();
    }

    public function test_changes_are_previewed_published_as_a_version_and_rolled_back(): void
    {
        $this->engine->upsertEntry($this->engine->defaultAllowList('student'), 'lesson.example.org', ['source' => 'manual']);

        $page = $this->actingAs($this->it)->get(route('admin.policy.changes.index'))->assertOk();
        $page->assertSee('Allow +')->assertSee('lesson.example.org')->assertSee('No versions yet');
        $this->assertFalse($this->studentsAllow('lesson.example.org'), 'Previewing changes nothing');

        $this->actingAs($this->it)->post(route('admin.policy.changes.publish'))->assertSessionHas('success');
        $this->assertTrue($this->studentsAllow('lesson.example.org'));
        $release = PolicyRelease::query()->sole();
        $this->assertSame(1, $release->number);
        $this->assertSame('published', $release->status);
        $this->assertSame($this->it->id, $release->user_id);
        $this->assertCount(1, $release->pushes);

        $this->actingAs($this->it)->get(route('admin.policy.versions.show', $release))->assertOk()->assertSee('v1')->assertSee('lesson.example.org');
        $this->actingAs($this->it)->get(route('admin.policy.changes.index'))->assertSee('The controller matches the policy');

        $this->actingAs($this->it)->post(route('admin.policy.versions.rollback', $release))->assertSessionHas('success');
        $this->assertFalse($this->studentsAllow('lesson.example.org'));
        $rollback = PolicyRelease::query()->where('rollback_of_id', $release->id)->sole();
        $this->assertSame(2, $rollback->number);
        $this->assertFalse($release->refresh()->canRollBack(), 'A version is rolled back once');
    }

    public function test_publishing_with_nothing_to_change_keeps_no_version(): void
    {
        app(PolicyPublisher::class)->publishAll($this->it);

        $this->assertSame(0, PolicyRelease::query()->count());
    }

    public function test_automatic_pushes_and_publish_all_each_make_one_version(): void
    {
        $this->engine->upsertEntry($this->engine->defaultAllowList('student'), 'one.example.org', ['source' => 'manual']);
        app(ControllerPusher::class)->push($this->students, null, 'schedule');
        $this->engine->upsertEntry($this->engine->defaultAllowList('student'), 'two.example.org', ['source' => 'manual']);
        app(ControllerPusher::class)->pushAll(null, 'schedule');

        $this->assertSame([1, 2], PolicyRelease::query()->orderBy('id')->pluck('number')->all());
        $this->assertSame('Scheduled push', PolicyRelease::query()->first()->triggerLabel());
    }

    private function studentsAllow(string $domain): bool
    {
        return in_array($domain, collect((new MockNetworkProvider)->contentFilters())->firstWhere('id', 'sim-filter-students')['allow'], true);
    }
}
