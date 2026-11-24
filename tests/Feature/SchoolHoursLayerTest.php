<?php

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Models\FilterPolicy;
use App\Models\NetworkAssignment;
use App\Models\PolicyEntry;
use App\Models\User;
use App\Services\Network\FilterSchedule;
use App\Services\Network\MockNetworkProvider;
use App\Services\Policy\ControllerLearner;
use App\Services\Policy\ControllerSync;
use App\Services\Policy\PolicyEngine;
use App\Support\Reach;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

/**
 * A school-hours filter beside an always-on one for the same type ("Student
 * - School Time" and "Students - All Hours"): learned as a school-hours
 * layer, it keeps its own allows and blocks and UniFi's schedule, gets the
 * policy's allows so approvals work in school hours too, can keep an
 * approval to outside school hours, and networks that only have it are
 * explained and offered the all-hours filter.
 */
class SchoolHoursLayerTest extends TestCase
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

        $filters = (new MockNetworkProvider)->contentFilters();
        $filters[] = ['id' => 'sim-school-time', 'name' => 'Student - School Time', 'enabled' => true, 'allow' => ['class-tool.example.org'], 'block' => ['games.example.net'],
            'network_ids' => ['sim-student', 'sim-guest'], 'categories' => ['ADULT', 'GAMES'], 'safe_search' => [], 'schedule' => 'EVERY_WEEK',
            'schedule_label' => FilterSchedule::label(['mode' => 'EVERY_WEEK', 'repeat_on_days' => ['mon', 'tue', 'wed', 'thu', 'fri'], 'time_all_day' => false, 'time_range_start' => '08:30', 'time_range_end' => '15:10'])];
        Cache::forever('mock.content_filters', $filters);
    }

    public function test_schedules_read_in_words(): void
    {
        $this->assertSame('Always', FilterSchedule::label(['mode' => 'ALWAYS']));
        $this->assertSame('Mon–Fri 08:30–15:10', FilterSchedule::label(['mode' => 'EVERY_WEEK', 'repeat_on_days' => ['fri', 'mon', 'tue', 'wed', 'thu'], 'time_range_start' => '8:30', 'time_range_end' => '15:10']));
        $this->assertSame('Mon, Wed all day', FilterSchedule::label(['mode' => 'EVERY_WEEK', 'repeat_on_days' => ['mon', 'wed'], 'time_all_day' => true]));
        $this->assertSame('Every day 07:00–22:00', FilterSchedule::label(['mode' => 'EVERY_DAY', 'time_range_start' => '07:00', 'time_range_end' => '22:00']));
        $this->assertSame('On a schedule', FilterSchedule::label(['mode' => 'CUSTOM']));
        $this->assertSame('On a schedule', FilterSchedule::label('EVERY_WEEK'));
    }

    public function test_it_becomes_a_school_hours_layer_that_keeps_both_lists_and_gets_the_policys_allows(): void
    {
        $learned = app(ControllerLearner::class)->learn(app(ControllerSync::class)->state(true));
        $this->assertSame('hours', $learned['filters']['sim-school-time']['role']);
        $this->assertSame('Mon–Fri 08:30–15:10', $learned['filters']['sim-school-time']['when']);
        $this->actingAs($this->admin)->get(route('admin.policy.setup.show'))->assertOk()
            ->assertSee('School-hours layer of Students')->assertSee('Mon–Fri 08:30–15:10');

        $this->actingAs($this->admin)->post(route('admin.policy.setup.import-all'))->assertSessionHas('success');
        $students = FilterPolicy::query()->where('provider_reference', 'sim-filter-students')->sole();
        $layer = FilterPolicy::query()->where('provider_reference', 'sim-school-time')->sole();
        $this->assertTrue($layer->isHoursLayer());
        $this->assertTrue($layer->layerOf->is($students));
        $this->assertNull($students->layer, 'A school-hours layer is not the blocklist-feed layer');
        $this->assertSame($students->id, NetworkAssignment::query()->where('provider_network_id', 'sim-guest')->value('filter_policy_id'), 'Its networks belong to the policy');

        // Its own allows and blocks are kept, and nothing it has is published away.
        $desired = $this->engine->desired($layer);
        $this->assertContains('class-tool.example.org', $desired['allow']);
        $this->assertContains('games.example.net', $desired['block']);
        $this->assertContains('example.edu', $desired['allow'], "Students' allows go into it too");
        $this->assertNotContains('games.example.net', $this->engine->desired($students)['block'], 'Its blocks stay in it, out of the all-hours filter');
        $this->assertSame([], $this->engine->reconcile($layer)['allow_remove']);

        // An approval for students reaches both filters; one kept to outside school hours reaches only the all-hours filter.
        $request = $this->pendingRequest(['homework.example.com'], Reach::types('student'));
        $this->actingAs($this->admin)->get(route('requests.show', $request))->assertOk()->assertSee('Outside school hours only');
        $this->actingAs($this->admin)->post(route('it.requests.action', $request), ['action' => 'approve', 'domains' => ['homework.example.com'], 'scope' => ['student'], 'smart' => '0'])->assertSessionHas('success');
        $this->assertContains('homework.example.com', $this->engine->desired($layer)['allow']);

        $evening = $this->pendingRequest(['game-night.example.com'], Reach::types('student'));
        $this->actingAs($this->admin)->post(route('it.requests.action', $evening), ['action' => 'approve', 'domains' => ['game-night.example.com'], 'scope' => ['student'], 'hours' => 'outside', 'smart' => '0'])->assertSessionHas('success');
        $this->assertSame('outside', PolicyEntry::query()->where('domain', 'game-night.example.com')->value('hours'));
        $this->assertContains('game-night.example.com', $this->engine->desired($students)['allow']);
        $this->assertNotContains('game-night.example.com', $this->engine->desired($layer)['allow']);

        // The policy page explains it.
        $this->actingAs($this->admin)->get(route('admin.policy.policies.show', $layer))->assertOk()->assertSee('School-hours layer of');
    }

    public function test_a_network_with_only_the_school_hours_filter_is_explained_and_offered_the_all_hours_one(): void
    {
        $this->actingAs($this->admin)->post(route('admin.policy.setup.import-all'));
        $this->artisan('policy:check-sync')->assertSuccessful();

        $students = FilterPolicy::query()->where('provider_reference', 'sim-filter-students')->sole();
        $this->assertNotSame('networks', $students->sync_status, 'Not a mismatch: the network has the school-hours layer');
        $this->assertStringContainsString('Filtered only in school hours on 1 network(s)', $students->sync_details['summary']);
        $this->assertStringContainsString('Guest Wi-Fi', $students->sync_details['summary']);

        $this->actingAs($this->admin)->get(route('admin.policy.networks.index'))->assertOk()
            ->assertSee('filtering only in school hours')->assertSee('Add Students')->assertSee('school hours');

        $guest = NetworkAssignment::query()->where('provider_network_id', 'sim-guest')->sole();
        $this->actingAs($this->admin)->post(route('admin.policy.networks.filter', $guest), ['filter_id' => 'sim-filter-students'])->assertSessionHas('success');
        $this->assertContains('sim-guest', collect(app(MockNetworkProvider::class)->contentFilters())->firstWhere('id', 'sim-filter-students')['network_ids']);
    }

    public function test_a_filter_brought_in_as_its_own_policy_is_turned_into_the_layer_without_losing_entries(): void
    {
        // As on an earlier version: School Time imported as a second Students policy.
        $this->actingAs($this->admin)->post(route('admin.policy.networks.link'), ['filter_id' => 'sim-school-time', 'managed_by' => 'new:student'])->assertSessionHas('success');
        $own = FilterPolicy::query()->where('provider_reference', 'sim-school-time')->sole();
        $this->assertFalse($own->isLayer());
        $this->engine->upsertEntry($this->engine->ownList($own, ListKind::Allow), 'kept.example.org', ['source' => 'manual']);

        $students = FilterPolicy::query()->main()->where('provider_reference', 'sim-filter-students')->first()
            ?? tap(FilterPolicy::query()->main()->where('audience', 'student')->whereKeyNot($own->id)->firstOrFail(), fn (FilterPolicy $policy) => app(ControllerSync::class)->link(collect(app(MockNetworkProvider::class)->contentFilters())->firstWhere('id', 'sim-filter-students'), $policy, $this->admin));
        $this->actingAs($this->admin)->post(route('admin.policy.networks.link'), ['filter_id' => 'sim-school-time', 'managed_by' => 'hours:'.$students->id])
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'school-hours layer of'));

        $layer = $own->refresh();
        $this->assertTrue($layer->isHoursLayer());
        $this->assertContains('kept.example.org', $this->engine->desired($layer)['allow']);
        $this->assertSame(0, FilterPolicy::query()->main()->where('provider_reference', 'sim-school-time')->count());
    }
}
