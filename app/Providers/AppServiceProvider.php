<?php

namespace App\Providers;

use App\Models\IntegrationConfig;
use App\Services\Policy\PolicyPublisher;
use App\Services\SchoolCalendar;
use App\Services\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
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
        // Scoped: one instance per request or queued job, so long-running
        // workers still see settings changed elsewhere.
        $this->app->scoped(SettingsRepository::class);
        // Holds the policy version being written, for the length of one request or job.
        $this->app->scoped(PolicyPublisher::class);
        $this->app->scoped(SchoolCalendar::class);
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
                return $this->copy()->setTimezone(app(SettingsRepository::class)->timezone());
            });
        }

        Paginator::defaultView('pagination.default');

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));
        // Public website checks run a real browser on each probe: 5 a minute per address, 60 a minute in all.
        RateLimiter::for('public-check', fn (Request $request) => [Limit::perMinute(5)->by('ip:'.$request->ip()), Limit::perMinute(60)->by('all')]);

        try {
            $settings = app(SettingsRepository::class);
            $school = $settings->get('school', []);
            $branding = (array) $settings->get('branding', []);
            // The product name can be changed (for example to "uConnect"); without one it stays "<School> Filterdesk".
            config([
                'app.product_name' => filled($branding['app_name'] ?? null) ? $branding['app_name'] : 'Filterdesk',
                'app.brand_mark' => filled($branding['mark'] ?? null) ? $branding['mark'] : 'IT',
                'app.icon_version' => substr(md5((string) ($branding['icon_data'] ?? '')), 0, 8),
            ]);
            if (filled($branding['app_name'] ?? null)) {
                config(['app.name' => $branding['app_name']]);
            }
            if ($school) {
                config([
                    'app.name' => filled($branding['app_name'] ?? null) ? $branding['app_name'] : (($school['name'] ?? '') !== '' ? $school['name'].' Filterdesk' : config('app.name')),
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
                    // "None" means plain SMTP (for example an internal relay on port 25): never upgrade to TLS,
                    // even when the server offers STARTTLS. Otherwise TLS is used when offered.
                    'mail.mailers.smtp.auto_tls' => ($mail['encryption'] ?? 'tls') !== 'none',
                    // Accept a certificate that does not match the host (for example connecting by IP).
                    'mail.mailers.smtp.verify_peer' => (bool) ($mail['verify_tls'] ?? true),
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
