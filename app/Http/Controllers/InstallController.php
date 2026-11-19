<?php

namespace App\Http\Controllers;

use App\Models\IntegrationConfig;
use App\Models\NetworkProfile;
use App\Models\User;
use App\Services\Oidc\OidcDiscoveryService;
use App\Services\SettingsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use Throwable;

class InstallController extends Controller
{
    public function show(Request $request, int $step = 1): View
    {
        abort_unless($step >= 1 && $step <= 8, 404);

        return view('install.wizard', [
            'step' => $step,
            'data' => $request->session()->get('installation', []),
            'checks' => $step === 1 ? $this->systemChecks() : [],
        ]);
    }

    public function store(Request $request, int $step): RedirectResponse
    {
        $data = $request->session()->get('installation', []);
        $validated = match ($step) {
            1 => [],
            2 => $request->validate([
                'school_name' => ['required', 'string', 'max:120'],
                'timezone' => ['required', 'timezone'],
                'app_url' => ['required', 'url', 'max:255'],
                'schoolbox_origin' => ['nullable', 'url', 'max:255'],
            ]),
            3 => $request->validate([
                'admin_name' => ['required', 'string', 'max:120'],
                'admin_email' => ['required', 'email'],
                'admin_password' => ['required', 'string', 'min:14', 'confirmed'],
                'oidc_enabled' => ['nullable', 'boolean'],
                'oidc_issuer' => ['nullable', 'required_if:oidc_enabled,1', 'url', 'starts_with:https://'],
                'oidc_client_id' => ['nullable', 'required_if:oidc_enabled,1', 'string'],
                'oidc_client_secret' => ['nullable', 'required_if:oidc_enabled,1', 'string'],
                'oidc_allowed_domains' => ['nullable', 'string'],
                'emergency_local_login' => ['nullable', 'boolean'],
            ]),
            4 => $request->validate([
                'unifi_enabled' => ['nullable', 'boolean'],
                'unifi_controller_url' => ['nullable', 'required_if:unifi_enabled,1', 'url', 'starts_with:https://'],
                'unifi_api_key' => ['nullable', 'required_if:unifi_enabled,1', 'string'],
                'unifi_site_id' => ['nullable', 'string'],
                'unifi_verify_tls' => ['nullable', 'boolean'],
                'unifi_mode' => ['required', 'in:read_only,active'],
            ]),
            5 => $request->validate([
                'student_probe_url' => ['required', 'url'],
                'staff_probe_url' => ['required', 'url'],
                'student_network_id' => ['nullable', 'string'],
                'staff_network_id' => ['nullable', 'string'],
            ]),
            6 => $request->validate([
                'mail_host' => ['nullable', 'string'], 'mail_port' => ['nullable', 'integer'],
                'mail_username' => ['nullable', 'string'], 'mail_password' => ['nullable', 'string'],
                'mail_from_address' => ['nullable', 'email'], 'it_notification_address' => ['required', 'email'],
            ]),
            7 => $request->validate([
                'auto_approve_staff' => ['nullable', 'boolean'],
                'default_temporary_days' => ['required', 'integer', 'min:1', 'max:365'],
                'categories' => ['required', 'string'],
            ]),
            8 => [],
            default => abort(404),
        };

        foreach (['oidc_enabled', 'emergency_local_login', 'unifi_enabled', 'unifi_verify_tls', 'auto_approve_staff'] as $boolean) {
            if (array_key_exists($boolean, $request->all())) {
                $validated[$boolean] = $request->boolean($boolean);
            }
        }

        $data = array_merge($data, $validated);
        $request->session()->put('installation', $data);

        if ($step === 3 && ($data['oidc_enabled'] ?? false)) {
            app(OidcDiscoveryService::class)->discover($data['oidc_issuer']);
        }

        if ($step === 8) {
            $this->complete($data);
            $request->session()->forget('installation');

            return redirect()->route('login')->with('success', 'Installation complete. Sign in with the administrator account.');
        }

        return redirect()->route('install.show', $step + 1);
    }

    /** @return array<string, array{ok: bool, message: string}> */
    private function systemChecks(): array
    {
        try {
            DB::select('select 1');
            $database = ['ok' => true, 'message' => 'Database connection is ready.'];
        } catch (Throwable $exception) {
            $database = ['ok' => false, 'message' => 'Database unavailable: '.$exception->getMessage()];
        }

        return [
            'database' => $database,
            'app_key' => ['ok' => filled(config('app.key')), 'message' => filled(config('app.key')) ? 'Application encryption key is configured.' : 'APP_KEY must be generated.'],
            'queue' => ['ok' => config('queue.default') !== 'sync', 'message' => 'Queue driver: '.config('queue.default')],
        ];
    }

    /** @param array<string, mixed> $data */
    private function complete(array $data): void
    {
        DB::transaction(function () use ($data): void {
            $settings = app(SettingsRepository::class);
            $settings->set('school', [
                'name' => $data['school_name'], 'timezone' => $data['timezone'],
                'app_url' => $data['app_url'], 'schoolbox_origin' => $data['schoolbox_origin'] ?? null,
            ]);
            $settings->set('auth.emergency_local_login', (bool) ($data['emergency_local_login'] ?? false));
            $settings->set('notifications', ['it_address' => $data['it_notification_address']]);
            $settings->set('approval', [
                'auto_approve_staff' => (bool) ($data['auto_approve_staff'] ?? false),
                'default_temporary_days' => (int) $data['default_temporary_days'],
                'categories' => array_values(array_filter(array_map('trim', explode(',', $data['categories'])))),
            ]);

            User::query()->updateOrCreate(['email' => strtolower($data['admin_email'])], [
                'name' => $data['admin_name'], 'password' => Hash::make($data['admin_password']),
                'role' => 'admin', 'enabled' => true, 'email_verified_at' => now(),
            ]);

            if ($data['oidc_enabled'] ?? false) {
                IntegrationConfig::query()->create([
                    'provider' => 'oidc', 'name' => 'OpenID Connect', 'mode' => 'active', 'enabled' => true,
                    'configuration' => [
                        'issuer' => $data['oidc_issuer'], 'client_id' => $data['oidc_client_id'],
                        'allowed_domains' => array_values(array_filter(array_map('trim', explode(',', $data['oidc_allowed_domains'] ?? '')))),
                    ],
                    'credentials' => ['client_secret' => $data['oidc_client_secret']],
                ]);
            }

            if (! empty($data['mail_host'])) {
                IntegrationConfig::query()->create([
                    'provider' => 'smtp', 'name' => 'Email notifications', 'mode' => 'active', 'enabled' => true,
                    'configuration' => [
                        'host' => $data['mail_host'], 'port' => $data['mail_port'] ?? 587,
                        'from' => $data['mail_from_address'] ?? $data['it_notification_address'],
                    ],
                    'credentials' => ['username' => $data['mail_username'] ?? null, 'password' => $data['mail_password'] ?? null],
                ]);
            }

            if ($data['unifi_enabled'] ?? false) {
                IntegrationConfig::query()->create([
                    'provider' => 'unifi', 'name' => 'UniFi Network', 'mode' => $data['unifi_mode'], 'enabled' => true,
                    'configuration' => [
                        'controller_url' => $data['unifi_controller_url'], 'site_id' => $data['unifi_site_id'] ?? null,
                        'verify_tls' => (bool) ($data['unifi_verify_tls'] ?? true), 'api_prefix' => '/proxy/network/integration/v1',
                    ],
                    'credentials' => ['api_key' => $data['unifi_api_key']],
                ]);
            } else {
                IntegrationConfig::query()->create(['provider' => 'mock', 'name' => 'Simulation', 'mode' => 'read_only', 'enabled' => true]);
            }

            NetworkProfile::query()->create(['key' => 'student', 'name' => 'Student Access', 'probe_url' => $data['student_probe_url'], 'unifi_network_id' => $data['student_network_id'] ?? null]);
            NetworkProfile::query()->create(['key' => 'staff', 'name' => 'Staff Access', 'probe_url' => $data['staff_probe_url'], 'unifi_network_id' => $data['staff_network_id'] ?? null]);
            $settings->set('installation.completed', true);
        });
    }
}
