<?php

namespace App\Services;

use App\Models\BellPeriod;
use App\Models\CalendarPeriod;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The school calendar as plain data, read once per request or job (and
 * cached for a minute), because schedules are evaluated for every entry.
 */
class SchoolCalendar
{
    public const CACHE_KEY = 'school.calendar';

    /** @var array{periods: list<array{id: int, name: string, kind: string, start: string, end: string}>, bells: list<array{id: int, name: string, from: string, to: string}>}|null */
    private ?array $data = null;

    /** @return array{periods: list<array{id: int, name: string, kind: string, start: string, end: string}>, bells: list<array{id: int, name: string, from: string, to: string}>} */
    public function data(): array
    {
        return $this->data ??= rescue(fn () => Cache::remember(self::CACHE_KEY, 60, fn (): array => [
            'periods' => CalendarPeriod::query()->orderBy('start_date')->get()->map(fn (CalendarPeriod $period) => [
                'id' => $period->id, 'name' => $period->name, 'kind' => $period->kind,
                'start' => $period->start_date->format('Y-m-d'), 'end' => $period->end_date->format('Y-m-d'),
            ])->all(),
            'bells' => BellPeriod::query()->orderBy('sort_order')->orderBy('starts_at')->get()->map(fn (BellPeriod $bell) => [
                'id' => $bell->id, 'name' => $bell->name, 'from' => $bell->starts_at, 'to' => $bell->ends_at,
            ])->all(),
        ]), ['periods' => [], 'bells' => []], false);
    }

    public function forget(): void
    {
        $this->data = null;
        Cache::forget(self::CACHE_KEY);
    }

    /** @return list<array{id: int, name: string, kind: string, start: string, end: string}> */
    public function periods(string $kind): array
    {
        return array_values(array_filter($this->data()['periods'], fn (array $period) => $period['kind'] === $kind));
    }

    /** @return list<array{id: int, name: string, from: string, to: string}> */
    public function bells(): array
    {
        return $this->data()['bells'];
    }

    /**
     * The terms a schedule follows: the chosen ones, or every term when none are chosen.
     *
     * @param  list<int>  $ids
     * @return list<array{id: int, name: string, kind: string, start: string, end: string}>
     */
    public function terms(array $ids = []): array
    {
        $terms = $this->periods('term');

        return $ids === [] ? $terms : array_values(array_filter($terms, fn (array $term) => in_array($term['id'], $ids, true)));
    }

    /** @param list<int> $termIds */
    public function isSchoolDay(CarbonInterface $date, array $termIds = []): bool
    {
        $day = $date->format('Y-m-d');
        $inTerm = collect($this->terms($termIds))->contains(fn (array $term) => $term['start'] <= $day && $term['end'] >= $day);

        return $inTerm && ! $this->within('holiday', $day);
    }

    public function isExamPeriod(CarbonInterface $at): bool
    {
        return $this->within('exam', CarbonImmutable::instance($at)->setTimezone(self::timezone())->format('Y-m-d'));
    }

    /**
     * Opening and closing times (HH:MM) for chosen bell periods, merged where
     * one period runs into the next, so a gap such as lunch is not included.
     *
     * @param  list<int>  $ids
     * @return list<array{0: string, 1: string}>
     */
    public function windows(array $ids): array
    {
        $chosen = array_values(array_filter($this->bells(), fn (array $bell) => in_array($bell['id'], $ids, true)));
        usort($chosen, fn (array $a, array $b) => strcmp($a['from'], $b['from']));
        $windows = [];
        foreach ($chosen as $bell) {
            $last = count($windows) - 1;
            // Periods within 10 minutes of each other (a bell change) count as one run.
            if ($last >= 0 && $this->minutes($bell['from']) - $this->minutes($windows[$last][1]) <= 10) {
                $windows[$last][1] = max($windows[$last][1], $bell['to']);
            } else {
                $windows[] = [$bell['from'], $bell['to']];
            }
        }

        return $windows;
    }

    public static function timezone(): string
    {
        return (string) (config('app.display_timezone') ?: config('app.timezone'));
    }

    private function within(string $kind, string $day): bool
    {
        return collect($this->periods($kind))->contains(fn (array $period) => $period['start'] <= $day && $period['end'] >= $day);
    }

    private function minutes(string $time): int
    {
        try {
            [$hours, $minutes] = array_map('intval', explode(':', $time));

            return $hours * 60 + $minutes;
        } catch (Throwable) {
            return 0;
        }
    }
}
