<?php

use App\Services\SettingsRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Schedule::command('requests:expire')->everyMinute()->withoutOverlapping();

Schedule::command('policy:push')->everyFiveMinutes()->withoutOverlapping();

// Timed access opens and closes on the minute: push just the affected filters.
Schedule::command('policy:push --boundaries')->everyMinute()->withoutOverlapping();

Schedule::command('blocklists:refresh')->hourly()->withoutOverlapping();

Schedule::command('requests:remind')->everyFifteenMinutes()->withoutOverlapping();

Schedule::command('policy:check-sync')->everyFifteenMinutes()->withoutOverlapping();

Schedule::call(fn () => Cache::forever('heartbeat.scheduler', now()->toIso8601String()))->name('scheduler-heartbeat')->everyMinute();

Schedule::command('policy:digest')
    ->dailyAt((function (): string {
        try {
            $time = (string) app(SettingsRepository::class)->get('notifications.digest_time', '07:30');

            return preg_match('/^\d{2}:\d{2}$/', $time) ? $time : '07:30';
        } catch (Throwable) {
            return '07:30';
        }
    })())
    ->timezone((function (): string {
        try {
            return app(SettingsRepository::class)->timezone();
        } catch (Throwable) {
            return 'UTC';
        }
    })())
    ->withoutOverlapping();
Schedule::command('probes:check')->everyTenMinutes()->withoutOverlapping()->runInBackground();
Schedule::command('categories:guard --refresh')->dailyAt('05:30')->withoutOverlapping()->runInBackground();
