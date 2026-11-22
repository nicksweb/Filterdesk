<?php

namespace App\Support;

use App\Services\SettingsRepository;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * A recurring access (or block) pattern: between two dates, on chosen days
 * of the week, every N weeks, either all day or between two hours.
 *
 * All-day occurrences switch on at 01:00 school time (the nightly change)
 * and off at midnight. Hourly occurrences open the margin (default an hour)
 * before the start hour and close the margin after the end hour. Times are
 * school-local, so daylight saving changes do not shift them.
 */
final class Schedule
{
    public const MAX_MONTHS = 6;

    public const ALL_DAY_FROM = '01:00';

    /** @param list<int> $days ISO weekdays, 1 = Monday … 7 = Sunday */
    private function __construct(
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly array $days,
        public readonly bool $allDay,
        public readonly string $from,
        public readonly string $to,
        public readonly int $everyWeeks,
    ) {}

    /** @param array<string, mixed>|null $data */
    public static function fromArray(?array $data): ?self
    {
        if (! $data || empty($data['start_date']) || empty($data['end_date'])) {
            return null;
        }

        return new self(
            (string) $data['start_date'], (string) $data['end_date'],
            array_values(array_map('intval', (array) ($data['days'] ?? []))),
            (bool) ($data['all_day'] ?? true),
            (string) ($data['from'] ?? '08:00'), (string) ($data['to'] ?? '15:00'),
            max(1, (int) ($data['every_weeks'] ?? 1)),
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
        $schedule = new self(
            (string) ($input['start_date'] ?? ''), (string) ($input['end_date'] ?? ''), $days,
            filter_var($input['all_day'] ?? true, FILTER_VALIDATE_BOOL),
            (string) ($input['from'] ?? '08:00'), (string) ($input['to'] ?? '15:00'),
            min(4, max(1, (int) ($input['every_weeks'] ?? 1))),
        );

        $errors = [];
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
            if ($end->lt(CarbonImmutable::today(self::timezone()))) {
                $errors['schedule.end_date'] = 'The last day has already passed.';
            }
        }
        if ($days === []) {
            $errors['schedule.days'] = 'Choose at least one day of the week.';
        }
        if (! $schedule->allDay && (! preg_match('/^\d{2}:00$/', $schedule->from) || ! preg_match('/^\d{2}:00$/', $schedule->to) || $schedule->to <= $schedule->from)) {
            $errors['schedule.to'] = 'Choose a start and end hour, with the end after the start.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $schedule;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['start_date' => $this->startDate, 'end_date' => $this->endDate, 'days' => $this->days, 'all_day' => $this->allDay,
            'from' => $this->from, 'to' => $this->to, 'every_weeks' => $this->everyWeeks];
    }

    /** Minutes the hourly windows open early and close late. */
    public static function margin(): int
    {
        return max(0, min(180, (int) app(SettingsRepository::class)->get('approval.schedule_margin_minutes', 60)));
    }

    public static function timezone(): string
    {
        return (string) (config('app.display_timezone') ?: config('app.timezone'));
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
            [$open, $close] = $this->window($date);
            if ($local->gte($open) && $local->lt($close)) {
                return true;
            }
        }

        return false;
    }

    /** First moment access is in place (the first occurrence's opening). */
    public function firstOpening(): ?CarbonImmutable
    {
        $start = $this->date($this->startDate);
        for ($date = $start, $guard = 0; $date && $guard < 400; $date = $date->addDay(), $guard++) {
            if ($this->occursOn($date)) {
                return $this->window($date)[0];
            }
        }

        return null;
    }

    /** When the last occurrence closes: the end of the schedule. */
    public function lastClosing(): CarbonImmutable
    {
        return $this->window($this->date($this->endDate) ?? CarbonImmutable::today(self::timezone()))[1];
    }

    /** e.g. "Mon–Fri, all day (from 1 am), 3 Mar – 30 Jun 2027" or "… 9 am – 3 pm, every 2 weeks". */
    public function label(): string
    {
        $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
        $days = match ($this->days) {
            [1, 2, 3, 4, 5] => 'Mon–Fri',
            [1, 2, 3, 4, 5, 6, 7] => 'Every day',
            [6, 7] => 'Weekends',
            default => implode(', ', array_map(fn (int $day) => $names[$day], $this->days)),
        };
        $hours = $this->allDay ? 'all day (from 1 am)' : $this->hour($this->from).' – '.$this->hour($this->to);
        $start = $this->date($this->startDate);
        $end = $this->date($this->endDate);
        $range = $start && $end ? $start->format($start->year === $end->year ? 'j M' : 'j M Y').' – '.$end->format('j M Y') : '';

        return "{$days}, {$hours}, {$range}".($this->everyWeeks > 1 ? ", every {$this->everyWeeks} weeks" : '');
    }

    private function occursOn(CarbonImmutable $date): bool
    {
        $start = $this->date($this->startDate);
        $end = $this->date($this->endDate);
        if (! $start || ! $end || $date->lt($start) || $date->gt($end) || ! in_array($date->dayOfWeekIso, $this->days, true)) {
            return false;
        }
        $weeks = intdiv((int) $start->startOfWeek()->diffInDays($date->startOfWeek(), true), 7);

        return $weeks % $this->everyWeeks === 0;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} opening and closing of one occurrence */
    private function window(CarbonImmutable $date): array
    {
        if ($this->allDay) {
            return [$date->setTimeFromTimeString(self::ALL_DAY_FROM), $date->addDay()->startOfDay()];
        }
        $margin = self::margin();

        return [$date->setTimeFromTimeString($this->from)->subMinutes($margin), $date->setTimeFromTimeString($this->to)->addMinutes($margin)];
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
