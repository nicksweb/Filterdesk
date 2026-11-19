<?php

namespace App\Providers;

use App\Models\IntegrationConfig;
use App\Services\SettingsRepository;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));

        try {
            $settings = app(SettingsRepository::class);
            $school = $settings->get('school', []);
            if ($school) {
                config([
                    'app.name' => $school['name'].' IT Requests',
                    'app.url' => $school['app_url'] ?? config('app.url'),
                    'app.timezone' => $school['timezone'] ?? config('app.timezone'),
                ]);
            }

            $smtp = IntegrationConfig::query()->where('provider', 'smtp')->where('enabled', true)->first();
            $mail = $smtp?->configuration ?? [];
            $credentials = $smtp?->credentials ?? [];
            if ($smtp && ! empty($mail['host'])) {
                config([
                    'mail.default' => 'smtp',
                    'mail.mailers.smtp.host' => $mail['host'],
                    'mail.mailers.smtp.port' => $mail['port'] ?? 587,
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
