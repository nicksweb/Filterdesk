<?php

namespace App\Services\Notifications;

use App\Services\SettingsRepository;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * When emails go out (Settings → Email and notifications → When emails are
 * sent): Monday to Friday, 7 am to 5 pm by default, in the school's
 * timezone. An email that comes up outside those hours waits in the queue
 * and is sent at the next opening. Security emails (sign-in locked, the
 * test email) are never held.
 */
class CommsHours
{
    public const DEFAULTS = ['enabled' => true, 'days' => [1, 2, 3, 4, 5], 'from' => '07:00', 'to' => '17:00'];

    public function __construct(private readonly SettingsRepository $settings) {}

    /** @return array{enabled: bool, days: list<int>, from: string, to: string} */
    public function settings(): array
    {
        $saved = (array) ($this->settings->get('notifications.hours') ?? []);
        $time = fn ($value, string $default) => is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : $default;
        $days = array_values(array_unique(array_filter(array_map('intval', (array) ($saved['days'] ?? self::DEFAULTS['days'])), fn (int $day) => $day >= 1 && $day <= 7)));
        sort($days);
        $hours = [
            'enabled' => (bool) ($saved['enabled'] ?? true),
            'days' => $days,
            'from' => $time($saved['from'] ?? null, self::DEFAULTS['from']),
            'to' => $time($saved['to'] ?? null, self::DEFAULTS['to']),
        ];
        // A window that can never open (no days, or ends before it starts) holds nothing back.
        if ($hours['days'] === [] || $hours['to'] <= $hours['from']) {
            $hours['enabled'] = false;
        }

        return $hours;
    }

    /** The next time an email may be sent: now, when inside the hours. */
    public function nextOpening(?CarbonInterface $now = null): Carbon
    {
        $now = Carbon::instance($now ?? now())->setTimezone($this->settings->timezone());
        $hours = $this->settings();
        if (! $hours['enabled']) {
            return $now;
        }
        for ($offset = 0; $offset <= 7; $offset++) {
            $day = $now->copy()->addDays($offset);
            if (! in_array($day->isoWeekday(), $hours['days'], true)) {
                continue;
            }
            $opens = $day->copy()->setTimeFromTimeString($hours['from']);
            $closes = $day->copy()->setTimeFromTimeString($hours['to']);
            if ($offset === 0 && $now->betweenIncluded($opens, $closes->copy()->subSecond())) {
                return $now;
            }
            if ($opens->greaterThan($now)) {
                return $opens;
            }
        }

        return $now;
    }

    /** When to send an email that is ready now: null to send straight away. */
    public function delayUntil(?CarbonInterface $now = null): ?Carbon
    {
        $now = Carbon::instance($now ?? now());
        $next = $this->nextOpening($now);

        return $next->greaterThan($now) ? $next->utc() : null;
    }

    /** "Monday to Friday, 7:00 am to 5:00 pm", for the settings page. */
    public function describe(): string
    {
        $hours = $this->settings();
        if (! $hours['enabled']) {
            return 'any time';
        }
        $names = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
        $days = $hours['days'] === [1, 2, 3, 4, 5] ? 'Monday to Friday' : ($hours['days'] === [1, 2, 3, 4, 5, 6, 7] ? 'every day' : implode(', ', array_map(fn (int $day) => $names[$day], $hours['days'])));
        $time = fn (string $value) => Carbon::createFromFormat('H:i', $value)->format('g:i a');

        return "{$days}, {$time($hours['from'])} to {$time($hours['to'])}";
    }
}
