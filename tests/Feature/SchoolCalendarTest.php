<?php

namespace Tests\Feature;

use App\Models\BellPeriod;
use App\Models\CalendarPeriod;
use App\Models\FilterPolicy;
use App\Models\IntegrationConfig;
use App\Models\NetworkType;
use App\Models\User;
use App\Services\Network\MockNetworkProvider;
use App\Services\Policy\PolicyEngine;
use App\Services\SchoolCalendar;
use App\Services\SettingsRepository;
use App\Support\Schedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

/**
 * Schedules that follow the school calendar (terms, holidays, bell times)
 * and exam mode per network type.
 */
class SchoolCalendarTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    private PolicyEngine $engine;

    private CalendarPeriod $term1;

    private CalendarPeriod $term2;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::flush();
        config(['app.display_timezone' => 'Australia/Brisbane']);
        $this->engine = $this->installWithPolicies('active');
        // Monday 1 March 2027 to Friday 26 March 2027, with a public holiday on Monday 15 March.
        $this->term1 = CalendarPeriod::query()->create(['name' => 'Term 1', 'kind' => 'term', 'start_date' => '2027-03-01', 'end_date' => '2027-03-26']);
        $this->term2 = CalendarPeriod::query()->create(['name' => 'Term 2', 'kind' => 'term', 'start_date' => '2027-04-19', 'end_date' => '2027-06-25']);
        CalendarPeriod::query()->create(['name' => 'Public holiday', 'kind' => 'holiday', 'start_date' => '2027-03-15', 'end_date' => '2027-03-15']);
        CalendarPeriod::query()->create(['name' => 'Exams', 'kind' => 'exam', 'start_date' => '2027-06-14', 'end_date' => '2027-06-18']);
        foreach ([['P1', '08:40', '09:40'], ['P2', '09:40', '10:40'], ['P3', '11:00', '12:00'], ['P4', '12:00', '13:00'], ['P5', '13:40', '14:40']] as $index => [$name, $from, $to]) {
            BellPeriod::query()->create(['name' => $name, 'starts_at' => $from, 'ends_at' => $to, 'sort_order' => $index]);
        }
        app(SchoolCalendar::class)->forget();
        $this->travelTo(CarbonImmutable::parse('2027-02-20 09:00', 'Australia/Brisbane'));
    }

    private function at(string $local): CarbonImmutable
    {
        return CarbonImmutable::parse($local, 'Australia/Brisbane');
    }

    private function bell(string $name): int
    {
        return BellPeriod::query()->where('name', $name)->value('id');
    }

    public function test_a_calendar_schedule_follows_terms_holidays_and_bell_times(): void
    {
        $schedule = Schedule::fromInput(['mode' => 'calendar', 'terms' => [$this->term1->id], 'days' => [1], 'all_day' => '0', 'periods' => [$this->bell('P3'), $this->bell('P4')]]);
        $margin = Schedule::margin();

        $this->assertTrue($schedule->isActive($this->at('2027-03-08 11:30')), 'Monday in term, period 3');
        $this->assertTrue($schedule->isActive($this->at('2027-03-08 13:00')->addMinutes($margin - 1)), 'Periods 3 and 4 run together, plus the margin');
        $this->assertFalse($schedule->isActive($this->at('2027-03-08 08:00')));
        $this->assertFalse($schedule->isActive($this->at('2027-03-09 11:30')), 'Tuesday is not chosen');
        $this->assertFalse($schedule->isActive($this->at('2027-03-15 11:30')), 'The public holiday is skipped');
        $this->assertFalse($schedule->isActive($this->at('2027-04-26 11:30')), 'Term 2 is not chosen');
        $this->assertSame('2027-03-01', $schedule->startDate);
        $this->assertSame('2027-03-26', $schedule->endDate);
        $this->assertStringContainsString('Term 1: Mon, P3, P4 (11:00–13:00), school days only', $schedule->label());
    }

    public function test_periods_apart_are_separate_windows(): void
    {
        app(SettingsRepository::class)->set('approval.schedule_margin_minutes', 0);
        $schedule = Schedule::fromInput(['mode' => 'calendar', 'terms' => [], 'days' => [1, 2, 3, 4, 5], 'all_day' => '0', 'periods' => [$this->bell('P1'), $this->bell('P5')]]);

        $this->assertTrue($schedule->isActive($this->at('2027-03-02 09:00')));
        $this->assertTrue($schedule->isActive($this->at('2027-05-04 14:00')), 'Every term when none are chosen');
        $this->assertFalse($schedule->isActive($this->at('2027-03-02 12:00')), 'Not between period 1 and period 5');
        $this->assertFalse($schedule->isActive($this->at('2027-04-06 09:00')), 'Not in the holidays between terms');
    }

    public function test_calendar_schedules_need_terms_and_periods(): void
    {
        CalendarPeriod::query()->where('kind', 'term')->delete();
        app(SchoolCalendar::class)->forget();

        $this->expectException(ValidationException::class);
        Schedule::fromInput(['mode' => 'calendar', 'days' => [1], 'all_day' => '1']);
    }

    public function test_exam_mode_blocks_only_apply_during_exams_and_are_pushed_when_exams_start(): void
    {
        IntegrationConfig::query()->where('provider', 'mock')->update(['configuration' => ['auto_push' => true, 'controller_authoritative' => false]]);
        $students = FilterPolicy::query()->where('audience', 'student')->whereNull('layer_of_id')->firstOrFail();
        $students->update(['provider_reference' => 'sim-filter-students']);
        $admin = User::factory()->admin()->create();
        $type = NetworkType::byKey('student');

        $this->actingAs($admin)->put(route('admin.settings.update', 'types'), ['types' => [$type->id => [
            'name' => $type->name, 'requestable' => '1', 'auto_approve' => '0', 'sort_order' => $type->sort_order, 'exam_mode' => 'calendar',
        ]]])->assertSessionHas('success');
        $examList = $this->engine->examList('student');
        $this->engine->upsertEntry($examList, 'chat.example.org', ['source' => 'manual']);

        $this->travelTo($this->at('2027-06-11 15:00'));
        $this->assertNotContains('chat.example.org', $this->engine->desired($students->refresh())['block'], 'Not during class time');
        $this->artisan('policy:push --boundaries')->assertSuccessful();

        $this->travelTo($this->at('2027-06-14 00:01'));
        $this->assertContains('chat.example.org', $this->engine->desired($students)['block'], 'Blocked in exam week');
        $this->artisan('policy:push --boundaries')->assertSuccessful();
        $this->assertContains('chat.example.org', collect((new MockNetworkProvider)->contentFilters())->firstWhere('id', 'sim-filter-students')['block'], 'Pushed when the exam period started');

        $this->assertNotContains('chat.example.org', $this->engine->desired(FilterPolicy::query()->where('audience', 'staff')->firstOrFail())['block'], 'Only the student type');
    }

    public function test_the_calendar_is_edited_under_settings(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.settings.show', 'calendar'))->assertOk()->assertSee('Term 1')->assertSee('P3');
        $this->actingAs($admin)->put(route('admin.settings.update', 'calendar'), [
            'new_period' => ['name' => 'Term 3', 'kind' => 'term', 'start_date' => '2027-07-12', 'end_date' => '2027-09-17'],
            'new_bell' => ['name' => 'P6', 'starts_at' => '14:40', 'ends_at' => '15:20'],
        ])->assertSessionHas('success');

        $this->assertSame(3, CalendarPeriod::query()->where('kind', 'term')->count());
        $this->assertContains('P6', array_column(app(SchoolCalendar::class)->bells(), 'name'));
    }
}
