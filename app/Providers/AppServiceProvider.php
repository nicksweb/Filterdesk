<?php

namespace App\Providers;

use App\Models\IntegrationConfig;
use App\Services\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Timestamps are stored in UTC; ->local() converts for display in the school's timezone.
        foreach ([Carbon::class, CarbonImmutable::class] as $carbon) {
            $carbon::macro('local', function () {
                /** @var Carbon|CarbonImmutable $this */
                return $this->copy()->setTimezone((string) config('app.display_timezone', config('app.timezone')));
            });
        }

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));

        try {
            $settings = app(SettingsRepository::class);
            $school = $settings->get('school', []);
            if ($school) {
                config([
                    'app.name' => ($school['name'] ?? '') !== '' ? $school['name'].' IT Requests' : config('app.name'),
                    'app.url' => $school['app_url'] ?? config('app.url'),
                    'app.display_timezone' => $settings->timezone(),
                ]);
                if (! empty($school['app_url'])) {
                    URL::forceRootUrl($school['app_url']);
                    if (str_starts_with($school['app_url'], 'https://')) {
                        URL::forceScheme('https');
                    }
                }
            }

            $smtp = IntegrationConfig::query()->where('provider', 'smtp')->where('enabled', true)->first();
            $mail = $smtp?->configuration ?? [];
            $credentials = $smtp?->credentials ?? [];
            if ($smtp && ! empty($mail['host'])) {
                config([
                    'mail.default' => 'smtp',
                    'mail.mailers.smtp.host' => $mail['host'],
                    'mail.mailers.smtp.port' => $mail['port'] ?? 587,
                    'mail.mailers.smtp.scheme' => ($mail['encryption'] ?? 'tls') === 'ssl' ? 'smtps' : 'smtp',
                    'mail.from.name' => $mail['from_name'] ?? config('app.name'),
                    'mail.mailers.smtp.username' => $credentials['username'] ?? null,
                    'mail.mailers.smtp.password' => $credentials['password'] ?? null,
                    'mail.from.address' => $mail['from'] ?? config('mail.from.address'),
                ]);
            }
        } catch (Throwable) {
            // The first-run installer must remain available before migrations.
        }
    }
}
