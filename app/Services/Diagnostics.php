<?php

namespace App\Services;

use App\Models\IntegrationConfig;
use App\Models\NetworkProfile;
use App\Services\Network\NetworkProviderManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Configuration and service checks for the admin diagnostics page. Messages
 * are deliberately generic: no hostnames, credentials or stack traces.
 */
class Diagnostics
{
    public function __construct(private readonly SettingsRepository $settings, private readonly NetworkProviderManager $providers) {}

    /** @return array<string, array{ok: bool, label: string, message: string, warn?: bool}> */
    public function run(bool $probeNetworks = true): array
    {
        $checks = [];

        try {
            DB::select('select 1');
            $checks['database'] = ['ok' => true, 'label' => 'Database', 'message' => 'Connected ('.DB::connection()->getDriverName().').'];
        } catch (Throwable) {
            $checks['database'] = ['ok' => false, 'label' => 'Database', 'message' => 'The database is unreachable.'];
        }

        $installed = (string) $this->settings->get(config('version.stored_version_key'), 'unknown');
        $code = (string) config('version.app');
        $checks['version'] = ['ok' => $installed === $code, 'label' => 'Version', 'message' => $installed === $code ? "Running {$code}." : "Code is {$code} but the database records {$installed}. Run `php artisan itrequests:upgrade`.", 'warn' => true];

        $checks['app_key'] = ['ok' => filled(config('app.key')), 'label' => 'Encryption key', 'message' => filled(config('app.key')) ? 'APP_KEY is set. Keep a copy with your backups.' : 'APP_KEY is missing.'];
        $checks['debug'] = ['ok' => ! config('app.debug'), 'label' => 'Debug mode', 'message' => config('app.debug') ? 'APP_DEBUG is on. Turn it off in production.' : 'Off.'];
        $checks['https'] = ['ok' => str_starts_with((string) config('app.url'), 'https://'), 'label' => 'Public URL', 'message' => (string) config('app.url'), 'warn' => true];
        $checks['proxies'] = ['ok' => trim((string) config('app.trusted_proxies')) !== '*', 'label' => 'Trusted proxies', 'message' => trim((string) config('app.trusted_proxies')) === '*' ? 'TRUSTED_PROXIES is "*", so forwarded headers from any client are trusted.' : 'Only configured proxies are trusted.'];

        $heartbeat = Cache::get('heartbeat.scheduler');
        $fresh = $heartbeat && Carbon::parse($heartbeat)->gt(now()->subMinutes(3));
        $checks['scheduler'] = ['ok' => $fresh, 'label' => 'Scheduler', 'message' => $fresh ? 'Running (expiry and digest jobs active).' : 'No heartbeat in the last 3 minutes. Is the scheduler container running?'];

        try {
            $pending = DB::table('jobs')->count();
            $oldest = DB::table('jobs')->min('created_at');
            $stuck = $oldest && (int) $oldest < now()->subMinutes(5)->getTimestamp();
            $failed = DB::table('failed_jobs')->count();
            $checks['queue'] = ['ok' => ! $stuck && config('queue.default') !== 'sync', 'label' => 'Queue', 'message' => "{$pending} pending, {$failed} failed.".($stuck ? ' Jobs have waited over 5 minutes. Is the queue worker running?' : '')];
        } catch (Throwable) {
            $checks['queue'] = ['ok' => false, 'label' => 'Queue', 'message' => 'Queue tables are unavailable.'];
        }

        $smtp = IntegrationConfig::query()->where('provider', 'smtp')->where('enabled', true)->exists();
        $checks['mail'] = ['ok' => $smtp, 'label' => 'Email', 'message' => $smtp ? 'SMTP configured. Use Settings → Email to send a test.' : 'No SMTP server configured, so notifications are written to the log.', 'warn' => true];

        $oidc = IntegrationConfig::query()->where('provider', 'oidc')->where('enabled', true)->exists();
        $checks['oidc'] = ['ok' => $oidc, 'label' => 'Single sign-on', 'message' => $oidc ? 'OIDC enabled.' : 'OIDC is not enabled; only local accounts can sign in.', 'warn' => true];

        $integration = $this->providers->integration();
        $checks['network'] = [
            'ok' => $integration !== null && $integration->provider !== 'mock' && $integration->last_test_status !== 'failed',
            'label' => 'Network provider',
            'message' => $integration ? ucfirst($integration->provider).' · '.str($integration->mode)->replace('_', ' ').($integration->last_tested_at ? ' · last test '.$integration->last_test_status.' '.$integration->last_tested_at->diffForHumans() : ' · not tested') : 'None configured.',
            'warn' => true,
        ];

        if ($probeNetworks) {
            foreach (NetworkProfile::query()->where('enabled', true)->orderBy('sort_order')->get() as $profile) {
                try {
                    $response = Http::connectTimeout(1)->timeout(2)->get(rtrim($profile->probe_url, '/').'/health');
                    $body = $response->json() ?? [];
                    $ok = $response->successful() && ($body['ok'] ?? false);
                    $message = $ok ? 'Healthy · DNS '.($body['dns'] ?? 'system').' · '.(($body['fresh_browser_per_run'] ?? false) ? 'fresh browser per run' : 'browser reused between runs (DNS may be cached)') : 'Probe did not report healthy.';
                } catch (Throwable) {
                    $ok = false;
                    $message = 'Probe unreachable.';
                }
                $checks["probe_{$profile->key}"] = ['ok' => $ok, 'label' => "Probe: {$profile->name}", 'message' => $message];
            }
        }

        return $checks;
    }
}
