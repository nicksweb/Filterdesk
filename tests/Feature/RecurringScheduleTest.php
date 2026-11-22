<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\FilterPolicy;
use App\Models\IntegrationConfig;
use App\Models\User;
use App\Models\WebsiteRequest;
use App\Services\Network\MockNetworkProvider;
use App\Services\Policy\PolicyEngine;
use App\Support\Schedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

class RecurringScheduleTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    private PolicyEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::flush();
        config(['app.display_timezone' => 'Australia/Brisbane']);
        // Monday 2 March 2026, 07:00 in Brisbane.
        $this->travelTo(CarbonImmutable::parse('2026-03-02 07:00', 'Australia/Brisbane'));
        $this->engine = $this->installWithPolicies('active');
        IntegrationConfig::query()->where('provider', 'mock')->update(['configuration' => ['auto_push' => true, 'controller_authoritative' => true]]);
        FilterPolicy::query()->where('audience', 'staff')->update(['provider_reference' => 'sim-filter-staff']);
        FilterPolicy::query()->where('audience', 'student')->update(['enabled' => false]);
    }

    private function at(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($time, 'Australia/Brisbane');
    }

    public function test_schedule_rules_for_days_hours_margins_all_day_and_every_n_weeks(): void
    {
        $hours = Schedule::fromInput(['start_date' => '2026-03-02', 'end_date' => '2026-03-31', 'days' => [1, 3], 'all_day' => '0', 'from' => '09:00', 'to' => '11:00', 'every_weeks' => 2]);
        $this->assertTrue($hours->isActive($this->at('2026-03-02 08:00')), 'Opens an hour early');
        $this->assertFalse($hours->isActive($this->at('2026-03-02 07:59')));
        $this->assertTrue($hours->isActive($this->at('2026-03-02 11:59')), 'Closes an hour late');
        $this->assertFalse($hours->isActive($this->at('2026-03-02 12:00')));
        $this->assertFalse($hours->isActive($this->at('2026-03-03 09:30')), 'Tuesday is not chosen');
        $this->assertFalse($hours->isActive($this->at('2026-03-09 09:30')), 'Off week');
        $this->assertTrue($hours->isActive($this->at('2026-03-16 09:30')), 'Every 2 weeks');

        $allDay = Schedule::fromInput(['start_date' => '2026-03-02', 'end_date' => '2026-03-06', 'days' => [1, 2, 3, 4, 5], 'all_day' => '1']);
        $this->assertFalse($allDay->isActive($this->at('2026-03-02 00:30')), 'All day switches on at 1 am');
        $this->assertTrue($allDay->isActive($this->at('2026-03-02 01:00')));
        $this->assertTrue($allDay->isActive($this->at('2026-03-06 23:59')));
        $this->assertFalse($allDay->isActive($this->at('2026-03-07 10:00')), 'Saturday and after the end');
        $this->assertSame('Mon–Fri, all day (from 1 am), 2 Mar – 6 Mar 2026', $allDay->label());

        $this->expectException(ValidationException::class);
        Schedule::fromInput(['start_date' => '2026-03-02', 'end_date' => '2026-10-01', 'days' => [1]]);
    }

    public function test_a_recurring_request_is_pushed_on_and_off_by_the_scheduler(): void
    {
        $teacher = User::factory()->teacher()->create();
        $this->actingAs($teacher)->post(route('requests.store'), [
            'request_type' => 'allow', 'domains' => 'lab.example.org', 'network_types' => ['staff'], 'category' => 'Teaching and learning',
            'use_case' => 'Weekly science practical.', 'standards_confirmed' => '1', 'access_duration' => 'recurring',
            'schedule' => ['start_date' => '2026-03-02', 'end_date' => '2026-05-29', 'days' => [1], 'all_day' => '0', 'from' => '09:00', 'to' => '10:00', 'every_weeks' => 1],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $request = WebsiteRequest::query()->sole();
        $this->assertStringStartsWith('Recurring: Mon, 9 am – 10 am', $request->windowLabel());

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('requests.show', $request))->assertOk()->assertSee('Recurring:')->assertDontSee('name="access_from"', false);
        $this->actingAs($admin)->post(route('it.requests.action', $request), ['action' => 'approve'])->assertSessionHas('success');
        $this->assertSame(RequestStatus::Active, $request->refresh()->status, 'Off right now, so it is settled as scheduled');

        $allowed = fn () => collect((new MockNetworkProvider)->contentFilters())->firstWhere('id', 'sim-filter-staff')['allow'];
        $this->assertNotContains('lab.example.org', $allowed());

        $this->artisan('policy:push --boundaries');
        $this->travelTo($this->at('2026-03-02 08:00:30'));
        $this->artisan('policy:push --boundaries')->assertSuccessful();
        $this->assertContains('lab.example.org', $allowed(), 'On an hour before 9 am');

        $this->travelTo($this->at('2026-03-02 11:00:30'));
        $this->artisan('policy:push --boundaries')->assertSuccessful();
        $this->assertNotContains('lab.example.org', $allowed(), 'Off an hour after 10 am');
    }
}
