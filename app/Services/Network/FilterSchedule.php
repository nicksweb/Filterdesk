<?php

namespace App\Services\Network;

use Illuminate\Support\Carbon;

/**
 * When a controller content filter applies, in words: "Always",
 * "Mon–Fri 08:30–15:10", "Every day 07:00–22:00" or "12 Feb – 30 Jun".
 *
 * UniFi describes it with a mode (ALWAYS, EVERY_DAY, EVERY_WEEK,
 * ONE_TIME_ONLY or CUSTOM), the days (repeat_on_days: mon, tue…), all day
 * or a time range (time_range_start, time_range_end) and, for one-off or
 * custom schedules, dates. Missing parts are left out, so an unexpected
 * shape still reads sensibly.
 */
class FilterSchedule
{
    private const DAYS = ['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun'];

    /** @param array<string, mixed>|string|null $schedule */
    public static function label(array|string|null $schedule): string
    {
        if (! is_array($schedule)) {
            return strtoupper((string) ($schedule ?: 'ALWAYS')) === 'ALWAYS' ? 'Always' : 'On a schedule';
        }
        $mode = strtoupper((string) ($schedule['mode'] ?? 'ALWAYS'));
        if ($mode === 'ALWAYS') {
            return 'Always';
        }

        $parts = array_values(array_filter([
            $mode === 'EVERY_DAY' ? 'Every day' : self::days($schedule['repeat_on_days'] ?? $schedule['days'] ?? []),
            self::dates($schedule),
            self::times($schedule),
        ]));

        return $parts === [] ? 'On a schedule' : implode(' ', $parts);
    }

    private static function days(mixed $days): ?string
    {
        $keys = collect(is_array($days) ? $days : [])->map(fn ($day) => substr(strtolower((string) $day), 0, 3))->filter(fn (string $day) => isset(self::DAYS[$day]))->unique();
        if ($keys->isEmpty()) {
            return null;
        }
        $order = array_keys(self::DAYS);
        $indexes = $keys->map(fn (string $day) => array_search($day, $order, true))->sort()->values();
        if ($indexes->count() === 7) {
            return 'Every day';
        }
        // Consecutive days as a range: Mon–Fri.
        if ($indexes->count() > 2 && $indexes->last() - $indexes->first() === $indexes->count() - 1) {
            return self::DAYS[$order[$indexes->first()]].'–'.self::DAYS[$order[$indexes->last()]];
        }

        return $indexes->map(fn (int $index) => self::DAYS[$order[$index]])->join(', ');
    }

    /** @param array<string, mixed> $schedule */
    private static function times(array $schedule): ?string
    {
        if (! empty($schedule['time_all_day'])) {
            return 'all day';
        }
        $start = $schedule['time_range_start'] ?? $schedule['start_time'] ?? null;
        $end = $schedule['time_range_end'] ?? $schedule['end_time'] ?? null;

        return filled($start) && filled($end) ? self::time((string) $start).'–'.self::time((string) $end) : null;
    }

    private static function time(string $value): string
    {
        return preg_match('/^(\d{1,2}):(\d{2})/', $value, $match) ? sprintf('%02d:%s', $match[1], $match[2]) : $value;
    }

    /** @param array<string, mixed> $schedule */
    private static function dates(array $schedule): ?string
    {
        $date = fn ($value): ?string => filled($value) ? rescue(fn () => Carbon::parse((string) $value)->format('j M Y'), null, false) : null;
        $start = $date($schedule['date_start'] ?? $schedule['date'] ?? null);
        $end = $date($schedule['date_end'] ?? null);

        return match (true) {
            $start && $end && $start !== $end => "{$start} – {$end}",
            (bool) $start => $start,
            default => null,
        };
    }
}
