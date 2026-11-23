<?php

namespace App\Support;

use App\Services\SchoolCalendar;
use App\Services\SettingsRepository;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * A recurring access (or block) pattern on chosen days of the week, every N
 * weeks, either all day or at set times.
 *
 * Two kinds:
 *  - dates: between two dates (up to 6 months), all day or between two hours;
 *  - calendar: during chosen school terms (every term when none are chosen),
 *    skipping holidays, all day or during chosen bell periods. It follows
 *    the school calendar, so moving a term date moves the schedule.
 *
 * All-day occurrences switch on at 01:00 school time (the nightly change)
 * and off at midnight. Timed occurrences open the margin (default an hour)
 * before and close the margin after. Times are school-local, so daylight
 * saving changes do not shift them.
 */
final class Schedule
{
    public const MAX_MONTHS = 6;

    /** A calendar schedule may run to the end of the terms chosen, up to this far ahead. */
    public const MAX_CALENDAR_MONTHS = 13;

    public const ALL_DAY_FROM = '01:00';

    /**
     * @param  list<int>  $days  ISO weekdays, 1 = Monday … 7 = Sunday
     * @param  list<int>  $terms  calendar term ids (calendar mode; empty = every term)
     * @param  list<int>  $periods  bell period ids (calendar mode, not all day)
     */
    private function __construct(
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly array $days,
        public readonly bool $allDay,
        public readonly string $from,
        public readonly string $to,
        public readonly int $everyWeeks,
        public readonly string $mode = 'dates',
        public readonly array $terms = [],
        public readonly array $periods = [],
    ) {}

    /** @param array<string, mixed>|null $data */
    public static function fromArray(?array $data): ?self
    {
        if (! $data) {
            return null;
        }
        $mode = ($data['mode'] ?? 'dates') === 'calendar' ? 'calendar' : 'dates';
        if ($mode === 'dates' && (empty($data['start_date']) || empty($data['end_date']))) {
            return null;
        }

        return new self(
            (string) ($data['start_date'] ?? ''), (string) ($data['end_date'] ?? ''),
            array_values(array_map('intval', (array) ($data['days'] ?? []))),
            (bool) ($data['all_day'] ?? true),
            (string) ($data['from'] ?? '08:00'), (string) ($data['to'] ?? '15:00'),
            max(1, (int) ($data['every_weeks'] ?? 1)),
            $mode,
            array_values(array_map('intval', (array) ($data['terms'] ?? []))),
            array_values(array_map('intval', (array) ($data['periods'] ?? []))),
        );
    }

    /**
     * Build and check a schedule from form input.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function fromInput(array $input): self
    {
        $days = array_values(array_unique(array_filter(array_map('intval', (array) ($input['days'] ?? [])), fn (int $day) => $day >= 1 && $day <= 7)));
        sort($days);
        $mode = ($input['mode'] ?? 'dates') === 'calendar' ? 'calendar' : 'dates';
        $calendar = app(SchoolCalendar::class);
        $ids = fn (string $key) => array_values(array_unique(array_map('intval', array_filter((array) ($input[$key] ?? []), 'is_numeric'))));
        $terms = $mode === 'calendar' ? array_values(array_intersect($ids('terms'), array_column($calendar->terms(), 'id'))) : [];
        $periods = $mode === 'calendar' ? array_values(array_intersect($ids('periods'), array_column($calendar->bells(), 'id'))) : [];
        $allDay = filter_var($input['all_day'] ?? true, FILTER_VALIDATE_BOOL);

        // A calendar schedule takes its first and last day from the terms it follows.
        $chosenTerms = $mode === 'calendar' ? $calendar->terms($terms) : [];
        $startDate = $mode === 'calendar' ? (string) (collect($chosenTerms)->min('start') ?? '') : (string) ($input['start_date'] ?? '');
        $endDate = $mode === 'calendar' ? (string) (collect($chosenTerms)->max('end') ?? '') : (string) ($input['end_date'] ?? '');

        $schedule = new self(
            $startDate, $endDate, $days, $allDay,
            (string) ($input['from'] ?? '08:00'), (string) ($input['to'] ?? '15:00'),
            min(4, max(1, (int) ($input['every_weeks'] ?? 1))),
            $mode, $terms, $periods,
        );

        $errors = [];
        $today = CarbonImmutable::today(self::timezone());
        if ($mode === 'calendar') {
            if ($chosenTerms === []) {
                $errors['schedule.terms'] = 'Add the school terms under Settings → School calendar first, then choose the terms.';
            } elseif (CarbonImmutable::parse($endDate, self::timezone())->lt($today)) {
                $errors['schedule.terms'] = 'The terms chosen have already ended.';
            } elseif (CarbonImmutable::parse($endDate, self::timezone())->gt($today->addMonthsNoOverflow(self::MAX_CALENDAR_MONTHS))) {
                $errors['schedule.terms'] = 'A calendar schedule can run up to '.self::MAX_CALENDAR_MONTHS.' months ahead; choose fewer terms.';
            }
            if (! $allDay && $periods === []) {
                $errors['schedule.periods'] = 'Choose at least one period, or All day.';
            }
        } else {
            $start = $schedule->date($schedule->startDate);
            $end = $schedule->date($schedule->endDate);
            if (! $start) {
                $errors['schedule.start_date'] = 'Choose the first day.';
            }
            if (! $end) {
                $errors['schedule.end_date'] = 'Choose the last day.';
            }
            if ($start && $end) {
                if ($end->lt($start)) {
                    $errors['schedule.end_date'] = 'The last day must be on or after the first day.';
                } elseif ($end->gt($start->addMonthsNoOverflow(self::MAX_MONTHS))) {
                    $errors['schedule.end_date'] = 'A schedule can cover up to '.self::MAX_MONTHS.' months.';
                }
                if ($end->lt($today)) {
                    $errors['schedule.end_date'] = 'The last day has already passed.';
                }
            }
            if (! $allDay && (! preg_match('/^\d{2}:00$/', $schedule->from) || ! preg_match('/^\d{2}:00$/', $schedule->to) || $schedule->to <= $schedule->from)) {
                $errors['schedule.to'] = 'Choose a start and end hour, with the end after the start.';
            }
        }
        if ($days === []) {
            $errors['schedule.days'] = 'Choose at least one day of the week.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $schedule;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['mode' => $this->mode, 'start_date' => $this->startDate, 'end_date' => $this->endDate, 'days' => $this->days, 'all_day' => $this->allDay,
            'from' => $this->from, 'to' => $this->to, 'every_weeks' => $this->everyWeeks, 'terms' => $this->terms, 'periods' => $this->periods];
    }

    /** Minutes the timed windows open early and close late. */
    public static function margin(): int
    {
        return max(0, min(180, (int) app(SettingsRepository::class)->get('approval.schedule_margin_minutes', 60)));
    }

    public static function timezone(): string
    {
        return SchoolCalendar::timezone();
    }

    /** Whether the schedule is on at a moment. */
    public function isActive(CarbonInterface $at): bool
    {
        $local = CarbonImmutable::instance($at)->setTimezone(self::timezone());
        foreach ([$local->subDay(), $local, $local->addDay()] as $day) {
            $date = $day->startOfDay();
            if (! $this->occursOn($date)) {
                continue;
            }
            foreach ($this->windows($date) as [$open, $close]) {
                if ($local->gte($open) && $local->lt($close)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** First moment access is in place (the first occurrence's opening). */
    public function firstOpening(): ?CarbonImmutable
    {
        $start = $this->date($this->firstDate());
        for ($date = $start, $guard = 0; $date && $guard < 400; $date = $date->addDay(), $guard++) {
            if ($this->occursOn($date) && ($windows = $this->windows($date)) !== []) {
                return $windows[0][0];
            }
        }

        return null;
    }

    /** When the last occurrence closes: the end of the schedule. */
    public function lastClosing(): CarbonImmutable
    {
        $end = $this->date($this->lastDate()) ?? CarbonImmutable::today(self::timezone());
        $windows = $this->windows($end);

        return $windows === [] ? $end->addDay()->startOfDay() : end($windows)[1];
    }

    /** e.g. "Mon–Fri, all day (from 1 am), 3 Mar – 30 Jun 2027" or "Term 1, Term 2: Mon, Wed, periods 3–4". */
    public function label(): string
    {
        $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
        $days = match ($this->days) {
            [1, 2, 3, 4, 5] => 'Mon–Fri',
            [1, 2, 3, 4, 5, 6, 7] => 'Every day',
            [6, 7] => 'Weekends',
            default => implode(', ', array_map(fn (int $day) => $names[$day] ?? '?', $this->days)),
        };
        $repeat = $this->everyWeeks > 1 ? ", every {$this->everyWeeks} weeks" : '';

        if ($this->mode === 'calendar') {
            $calendar = app(SchoolCalendar::class);
            $terms = $this->terms === [] ? 'Every term' : implode(', ', array_column($calendar->terms($this->terms), 'name'));
            $bells = array_values(array_filter($calendar->bells(), fn (array $bell) => in_array($bell['id'], $this->periods, true)));
            $times = $this->allDay ? 'all day (from 1 am)' : implode(', ', array_column($bells, 'name')).' ('.implode(', ', array_map(fn (array $window) => "{$window[0]}–{$window[1]}", $calendar->windows($this->periods))).')';

            return "{$terms}: {$days}, {$times}, school days only{$repeat}";
        }

        $hours = $this->allDay ? 'all day (from 1 am)' : $this->hour($this->from).' – '.$this->hour($this->to);
        $start = $this->date($this->startDate);
        $end = $this->date($this->endDate);
        $range = $start && $end ? $start->format($start->year === $end->year ? 'j M' : 'j M Y').' – '.$end->format('j M Y') : '';

        return "{$days}, {$hours}, {$range}{$repeat}";
    }

    private function firstDate(): string
    {
        return $this->mode === 'calendar' ? (string) (collect(app(SchoolCalendar::class)->terms($this->terms))->min('start') ?? $this->startDate) : $this->startDate;
    }

    private function lastDate(): string
    {
        return $this->mode === 'calendar' ? (string) (collect(app(SchoolCalendar::class)->terms($this->terms))->max('end') ?? $this->endDate) : $this->endDate;
    }

    private function occursOn(CarbonImmutable $date): bool
    {
        $start = $this->date($this->firstDate());
        $end = $this->date($this->lastDate());
        if (! $start || ! $end || $date->lt($start) || $date->gt($end) || ! in_array($date->dayOfWeekIso, $this->days, true)) {
            return false;
        }
        if ($this->mode === 'calendar' && ! app(SchoolCalendar::class)->isSchoolDay($date, $this->terms)) {
            return false;
        }
        $weeks = intdiv((int) $start->startOfWeek()->diffInDays($date->startOfWeek(), true), 7);

        return $weeks % $this->everyWeeks === 0;
    }

    /** @return list<array{0: CarbonImmutable, 1: CarbonImmutable}> opening and closing of each window that day */
    private function windows(CarbonImmutable $date): array
    {
        if ($this->allDay) {
            return [[$date->setTimeFromTimeString(self::ALL_DAY_FROM), $date->addDay()->startOfDay()]];
        }
        $margin = self::margin();
        $times = $this->mode === 'calendar' ? app(SchoolCalendar::class)->windows($this->periods) : [[$this->from, $this->to]];

        return array_map(fn (array $window) => [$date->setTimeFromTimeString($window[0])->subMinutes($margin), $date->setTimeFromTimeString($window[1])->addMinutes($margin)], $times);
    }

    private function date(string $value): ?CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d', $value, self::timezone())?->startOfDay();
    }

    private function hour(string $time): string
    {
        return CarbonImmutable::createFromFormat('H:i', $time)->format('g a');
    }
}
