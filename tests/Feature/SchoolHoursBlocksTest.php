<?php

namespace Tests\Feature;

use App\Enums\EntrySource;
use App\Models\AuditEvent;
use App\Models\FilterPolicy;
use App\Models\NetworkProfile;
use App\Models\PolicyEntry;
use App\Models\User;
use App\Models\WebsiteRequest;
use App\Services\Network\FilterSchedule;
use App\Services\Network\MockNetworkProvider;
use App\Services\Policy\PolicyEngine;
use App\Services\Requests\RequestFormSettings;
use App\Services\SettingsRepository;
use App\Support\Reach;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

/**
 * Blocks in school hours only: an approved block can go into the
 * school-hours layer instead of the always-on filter, the requester can
 * suggest it when the settings allow, IT's quick block or allow does the
 * same without a request, and students and staff get plain results.
 */
class SchoolHoursBlocksTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    private PolicyEngine $engine;

    private User $admin;

    private FilterPolicy $students;

    private FilterPolicy $layer;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::flush();
        $this->engine = $this->installWithPolicies('active');
        $this->admin = User::factory()->admin()->create();

        $filters = (new MockNetworkProvider)->contentFilters();
        $filters[] = ['id' => 'sim-school-time', 'name' => 'Student - School Time', 'enabled' => true, 'allow' => [], 'block' => [],
            'network_ids' => ['sim-student'], 'categories' => ['ARTIFICIAL_INTELLIGENCE'], 'safe_search' => [], 'schedule' => 'EVERY_WEEK',
            'schedule_label' => FilterSchedule::label(['mode' => 'EVERY_WEEK', 'repeat_on_days' => ['mon', 'tue', 'wed', 'thu', 'fri'], 'time_range_start' => '08:15', 'time_range_end' => '15:10'])];
        Cache::forever('mock.content_filters', $filters);
        $this->actingAs($this->admin)->post(route('admin.policy.setup.import-all'));
        $this->students = FilterPolicy::query()->where('provider_reference', 'sim-filter-students')->sole();
        $this->layer = FilterPolicy::query()->where('provider_reference', 'sim-school-time')->sole();
        $this->assertTrue($this->layer->isHoursLayer());
    }

    private function blockRequest(array $domains, array $attributes = []): WebsiteRequest
    {
        return $this->pendingRequest($domains, Reach::types('student'), ['request_type' => 'block'] + $attributes);
    }

    public function test_a_block_approved_for_school_hours_only_goes_into_the_school_hours_layer(): void
    {
        $request = $this->blockRequest(['gemini.example.com']);
        $this->actingAs($this->admin)->get(route('requests.show', $request))->assertOk()
            ->assertSee('When should this be blocked?')->assertSee('School hours only')->assertSee('Mon–Fri 08:15–15:10');

        $this->actingAs($this->admin)->post(route('it.requests.action', $request), ['action' => 'approve', 'domains' => ['gemini.example.com'], 'scope' => ['student'], 'hours' => 'school', 'smart' => '0'])
            ->assertSessionHas('success');

        $this->assertSame('school', $request->refresh()->hours);
        $this->assertContains('gemini.example.com', $this->engine->desired($this->layer)['block'], 'Blocked by the school-hours filter');
        $this->assertNotContains('gemini.example.com', $this->engine->desired($this->students)['block'], 'Not by the always-on filter, so it is open after hours');
        $this->assertStringContainsString('Blocked in school hours only: added to Student - School Time (Mon–Fri 08:15–15:10)', $request->events()->where('type', 'approved')->value('message'));
        $this->actingAs($this->admin)->get(route('requests.show', $request))->assertOk()->assertSee('School hours only');

        // Revoking takes it out of the layer again.
        $this->actingAs($this->admin)->post(route('it.requests.action', $request->refresh()), ['action' => 'revoke', 'message' => 'Not needed any more.']);
        $this->assertNotContains('gemini.example.com', $this->engine->desired($this->layer->refresh())['block']);
    }

    public function test_a_block_for_all_the_time_works_as_before(): void
    {
        $request = $this->blockRequest(['game.example.com']);
        $this->actingAs($this->admin)->post(route('it.requests.action', $request), ['action' => 'approve', 'domains' => ['game.example.com'], 'scope' => ['student'], 'hours' => 'all', 'smart' => '0']);

        $this->assertNull($request->refresh()->hours);
        $this->assertContains('game.example.com', $this->engine->desired($this->students)['block']);
    }

    public function test_the_requester_suggests_school_hours_when_the_setting_allows_it(): void
    {
        $teacher = User::factory()->teacher()->create();
        $this->actingAs($teacher)->get(route('requests.create', ['type' => 'block']))->assertOk()->assertDontSee('When should it be blocked?');

        app(SettingsRepository::class)->set('request_form', ['block_hours' => 'requester_school']);
        $this->actingAs($teacher)->get(route('requests.create', ['type' => 'block']))->assertOk()
            ->assertSee('When should it be blocked?')->assertSee('value="school" checked', false);

        $this->actingAs($teacher)->post(route('requests.store'), [
            'request_type' => 'block', 'domains' => 'chat.example.com', 'network_types' => ['student'], 'category' => 'Teaching and learning',
            'use_case' => 'Distracting during class time.', 'standards_confirmed' => '1', 'access_duration' => 'permanent', 'hours' => 'school',
        ])->assertRedirect();
        $request = WebsiteRequest::query()->latest('id')->firstOrFail();
        $this->assertSame('school', $request->hours);
        $this->actingAs($this->admin)->get(route('requests.show', $request))->assertOk()->assertSee('School hours only (requested)')->assertSee('Suggested by the requester');

        // "Always all the time" ignores it, and IT is not offered the choice.
        app(SettingsRepository::class)->set('request_form', ['block_hours' => 'always_all']);
        $this->assertSame('always_all', app(RequestFormSettings::class)->blockHours());
        $this->actingAs($this->admin)->get(route('requests.show', $request))->assertOk()->assertDontSee('When should this be blocked?');
    }

    public function test_quick_block_in_school_hours_previews_then_blocks_and_removes_a_conflicting_allow(): void
    {
        $this->engine->upsertEntry($this->engine->defaultAllowList('student'), 'gemini.example.com', ['source' => EntrySource::Manual]);
        $input = ['domains' => 'gemini.example.com', 'kind' => 'block', 'types' => ['student', 'staff'], 'when' => 'school', 'include_www' => '0'];

        $this->actingAs($this->admin)->get(route('admin.policy.quick.show', ['domains' => 'gemini.example.com']))->assertOk()->assertSee('gemini.example.com');
        $preview = $this->actingAs($this->admin)->post(route('admin.policy.quick.preview'), $input)->assertOk()
            ->assertSee('What this changes')->assertSee('Student - School Time block')->assertSee('Staff: no school-hours filter', false)
            ->assertSee('Entries that say the opposite');
        $allow = PolicyEntry::query()->where('domain', 'gemini.example.com')->sole();
        $preview->assertSee('value="'.$allow->id.'" checked', false);

        $this->actingAs($this->admin)->post(route('admin.policy.quick.apply'), $input + ['remove' => [$allow->id]])
            ->assertRedirect(route('admin.policy.quick.show'))->assertSessionHas('success', fn (string $message) => str_contains($message, 'in school hours only'));
        $this->assertModelMissing($allow);
        $this->assertContains('gemini.example.com', $this->engine->desired($this->layer)['block']);
        $this->assertNotContains('gemini.example.com', $this->engine->desired($this->students)['block']);
        $this->assertTrue(AuditEvent::query()->where('action', 'policy.quick_change')->exists());
    }

    public function test_quick_allow_everywhere_waits_for_a_second_officer(): void
    {
        User::factory()->create(['role' => 'it']);
        $input = ['domains' => 'tool.example.org', 'kind' => 'allow', 'types' => ['site'], 'when' => 'all', 'include_www' => '0'];
        $this->actingAs($this->admin)->post(route('admin.policy.quick.preview'), $input)->assertOk()->assertSee('waits for a second IT officer');
        $this->actingAs($this->admin)->post(route('admin.policy.quick.apply'), $input)->assertSessionHas('success', fn (string $message) => str_contains($message, 'second IT officer'));
        $this->assertSame('add', PolicyEntry::query()->where('domain', 'tool.example.org')->value('pending_action'));
    }

    public function test_quick_change_is_for_it_only(): void
    {
        $this->actingAs(User::factory()->teacher()->create())->get(route('admin.policy.quick.show'))->assertForbidden();
    }

    public function test_students_and_staff_see_one_plain_line_per_network(): void
    {
        NetworkProfile::query()->where('key', 'student')->update(['probe_url' => 'http://probe-student:3000', 'unifi_network_id' => 'sim-student', 'enabled' => true]);
        NetworkProfile::query()->where('key', 'staff')->update(['probe_url' => 'http://probe-staff:3000', 'enabled' => true]);
        $this->layer->update(['current_blocklist' => ['chat.example.com']]);
        Http::fake([
            'probe-student:3000/*' => Http::response(['results' => [['status' => 'blocked', 'block_method' => 'dns', 'addresses' => ['203.0.113.250'], 'hosts' => [], 'duration_ms' => 4]]]),
            'probe-staff:3000/*' => Http::response(['results' => [['status' => 'probe_error', 'message' => 'page.goto: Timeout 8000ms exceeded.', 'hosts' => []]]]),
        ]);

        $this->actingAs(User::factory()->teacher()->create())->post(route('checks.store'), ['websites' => 'chat.example.com'])->assertOk()
            ->assertSee('Blocked')->assertSee('in school hours (Mon–Fri 08:15–15:10), open outside them', false)->assertSee("Couldn't check right now")
            ->assertDontSee('203.0.113.250')->assertDontSee('page.goto')->assertDontSee('Re-run these checks')->assertSee('Request access');
        $this->actingAs($this->admin)->post(route('checks.store'), ['websites' => 'chat.example.com'])->assertOk()
            ->assertSee('203.0.113.250')->assertSee('page.goto')->assertSee('Quick block or allow');
    }
}
