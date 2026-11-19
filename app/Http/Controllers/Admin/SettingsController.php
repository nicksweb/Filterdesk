<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Audience;
use App\Http\Controllers\Controller;
use App\Models\FilterPolicy;
use App\Models\IntegrationConfig;
use App\Models\NetworkProfile;
use App\Notifications\TestEmailNotification;
use App\Services\AuditLogger;
use App\Services\Auth\UserProvisioningService;
use App\Services\Network\NetworkProviderManager;
use App\Services\Network\ReadsContentFilters;
use App\Services\Network\UniFiNetworkProvider;
use App\Services\Oidc\OidcDiscoveryService;
use App\Services\Policy\PolicyEngine;
use App\Services\SettingsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * Post-installation configuration. Secrets are write-only: a blank field
 * keeps the stored value, and stored values are never sent back to the browser.
 */
class SettingsController extends Controller
{
    public const SECTIONS = [
        'general' => 'School',
        'authentication' => 'Authentication',
        'email' => 'Email and notifications',
        'network' => 'Network provider',
        'profiles' => 'Network profiles',
        'approval' => 'Approval policy',
    ];

    public function show(string $section, SettingsRepository $settings, NetworkProviderManager $providers): View
    {
        abort_unless(array_key_exists($section, self::SECTIONS), 404);

        $oidc = IntegrationConfig::query()->where('provider', 'oidc')->first();
        $smtp = IntegrationConfig::query()->where('provider', 'smtp')->first();
        $network = $providers->integration() ?? IntegrationConfig::query()->whereIn('provider', NetworkProviderManager::PROVIDERS)->first();

        return view('admin.settings', [
            'section' => $section,
            'sections' => self::SECTIONS,
            'settings' => $settings,
            'oidc' => $oidc,
            'oidcHasSecret' => filled($oidc?->credentials['client_secret'] ?? null),
            'roleRules' => UserProvisioningService::formatRules($oidc?->configuration['role_rules'] ?? []),
            'smtp' => $smtp,
            'smtpHasPassword' => filled($smtp?->credentials['password'] ?? null),
            'network' => $network,
            'networkHasKey' => filled($network?->credentials['api_key'] ?? null),
            'discovery' => session('discovery'),
            'profiles' => NetworkProfile::query()->orderBy('sort_order')->orderBy('id')->get(),
            'redirectUri' => route('auth.oidc.callback'),
        ]);
    }

    public function update(Request $request, string $section, SettingsRepository $settings, AuditLogger $audit, PolicyEngine $policy): RedirectResponse
    {
        abort_unless(array_key_exists($section, self::SECTIONS), 404);

        match ($section) {
            'general' => $this->updateGeneral($request, $settings),
            'authentication' => $this->updateAuthentication($request, $settings),
            'email' => $this->updateEmail($request, $settings),
            'network' => $this->updateNetwork($request),
            'profiles' => $this->updateProfiles($request, $policy),
            'approval' => $this->updateApproval($request, $settings),
        };

        $audit->log("settings.{$section}_updated");

        return redirect()->route('admin.settings.show', $section)->with('success', self::SECTIONS[$section].' settings saved.');
    }

    public function testEmail(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate(['address' => ['required', 'email']]);

        try {
            Notification::route('mail', $validated['address'])->notifyNow(new TestEmailNotification($request->user()->name));
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Test email failed: '.str($exception->getMessage())->limit(200));
        }

        $audit->log('settings.test_email_sent', metadata: ['address' => $validated['address']]);

        return back()->with('success', "Test email sent to {$validated['address']}.");
    }

    public function testNetwork(NetworkProviderManager $providers): RedirectResponse
    {
        try {
            $result = $providers->current()->test();
            $providers->integration()?->update(['last_tested_at' => now(), 'last_test_status' => $result['ok'] ? 'ok' : 'failed', 'last_test_message' => $result['message']]);

            if ($result['ok'] && $providers->current() instanceof ReadsContentFilters && FilterPolicy::query()->whereNotNull('provider_reference')->doesntExist()) {
                return redirect()->route('admin.policy.setup.show')->with('success', $result['message'].' Next, bring the controller\'s content filters in.');
            }

            return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Connection test failed. Check the application log for details.');
        }
    }

    public function discover(Request $request, NetworkProviderManager $providers): RedirectResponse
    {
        try {
            $result = $providers->current()->discover($request->string('site_id')->toString() ?: null);
        } catch (Throwable $exception) {
            report($exception);
            $result = ['sites' => [], 'networks' => [], 'message' => 'Discovery failed. Check the application log.'];
        }

        $message = $result['message'] ?? null;

        return back()->with('discovery', $result)->with($message ? 'error' : 'success', $message ?? 'Discovery complete.');
    }

    private function updateGeneral(Request $request, SettingsRepository $settings): void
    {
        $validated = $request->validate([
            'school_name' => ['required', 'string', 'max:120'],
            'timezone' => ['required', 'timezone'],
            'app_url' => ['required', 'url', 'max:255'],
            'schoolbox_origin' => ['nullable', 'url', 'max:255', 'starts_with:https://'],
        ]);

        $settings->set('school', [
            'name' => $validated['school_name'],
            'timezone' => $validated['timezone'],
            'app_url' => rtrim($validated['app_url'], '/'),
            'schoolbox_origin' => filled($validated['schoolbox_origin'] ?? null) ? rtrim($validated['schoolbox_origin'], '/') : null,
        ]);
    }

    private function updateAuthentication(Request $request, SettingsRepository $settings): void
    {
        $existing = IntegrationConfig::query()->where('provider', 'oidc')->first();
        $validated = $request->validate([
            'oidc_enabled' => ['nullable', 'boolean'],
            'oidc_issuer' => ['nullable', 'required_if:oidc_enabled,1', 'url', 'starts_with:https://'],
            'oidc_client_id' => ['nullable', 'required_if:oidc_enabled,1', 'string', 'max:255'],
            'oidc_client_secret' => ['nullable', Rule::requiredIf(fn () => $request->boolean('oidc_enabled') && blank($existing?->credentials['client_secret'] ?? null)), 'string', 'max:1000'],
            'oidc_allowed_domains' => ['nullable', 'string', 'max:1000'],
            'role_rules' => ['nullable', 'string', 'max:5000'],
            'default_role' => ['required', 'in:student,teacher,deny'],
            'sync_roles' => ['nullable', 'boolean'],
            'emergency_local_login' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('oidc_enabled')) {
            app(OidcDiscoveryService::class)->discover($validated['oidc_issuer']);
        }

        if (! $request->boolean('emergency_local_login') && ! $request->boolean('oidc_enabled')) {
            throw ValidationException::withMessages(['emergency_local_login' => 'Keep at least one sign-in method enabled.']);
        }

        $oidc = $existing ?? new IntegrationConfig(['provider' => 'oidc', 'name' => 'OpenID Connect', 'mode' => 'active']);
        $oidc->fill([
            'enabled' => $request->boolean('oidc_enabled'),
            'configuration' => [
                'issuer' => $validated['oidc_issuer'] ?? ($oidc->configuration['issuer'] ?? null),
                'client_id' => $validated['oidc_client_id'] ?? ($oidc->configuration['client_id'] ?? null),
                'allowed_domains' => $this->commaList($validated['oidc_allowed_domains'] ?? ''),
                'role_rules' => UserProvisioningService::parseRules($validated['role_rules'] ?? ''),
                'default_role' => $validated['default_role'],
                'sync_roles' => $request->boolean('sync_roles'),
            ],
        ]);
        if (filled($validated['oidc_client_secret'] ?? null)) {
            $oidc->credentials = ['client_secret' => $validated['oidc_client_secret']];
        }
        $oidc->save();

        $settings->set('auth.emergency_local_login', $request->boolean('emergency_local_login'));
    }

    private function updateEmail(Request $request, SettingsRepository $settings): void
    {
        $validated = $request->validate([
            'mail_host' => ['nullable', 'string', 'max:255'],
            'mail_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'mail_encryption' => ['nullable', 'in:tls,ssl,none'],
            'mail_username' => ['nullable', 'string', 'max:255'],
            'mail_password' => ['nullable', 'string', 'max:1000'],
            'mail_from_address' => ['nullable', 'email'],
            'mail_from_name' => ['nullable', 'string', 'max:120'],
            'it_notification_address' => ['required', 'email'],
            'events' => ['nullable', 'array'],
            'events.*' => ['in:new_request,requester_reply,assignment,filter_sync'],
            'daily_digest' => ['nullable', 'boolean'],
            'digest_time' => ['required', 'date_format:H:i'],
        ]);

        $smtp = IntegrationConfig::query()->firstOrNew(['provider' => 'smtp'], ['name' => 'Email notifications', 'mode' => 'active']);
        $smtp->fill([
            'enabled' => filled($validated['mail_host'] ?? null),
            'configuration' => [
                'host' => $validated['mail_host'] ?? null,
                'port' => (int) ($validated['mail_port'] ?? 587),
                'encryption' => $validated['mail_encryption'] ?? 'tls',
                'from' => $validated['mail_from_address'] ?? $validated['it_notification_address'],
                'from_name' => $validated['mail_from_name'] ?? null,
            ],
        ]);
        $credentials = $smtp->credentials ?? [];
        $credentials['username'] = $validated['mail_username'] ?? null;
        if (filled($validated['mail_password'] ?? null)) {
            $credentials['password'] = $validated['mail_password'];
        }
        if ($request->boolean('clear_mail_password')) {
            $credentials['password'] = null;
        }
        $smtp->credentials = $credentials;
        $smtp->save();

        $events = $validated['events'] ?? [];
        $settings->set('notifications', [
            'it_address' => strtolower($validated['it_notification_address']),
            'events' => collect(['new_request', 'requester_reply', 'assignment', 'filter_sync'])->mapWithKeys(fn (string $event) => [$event => in_array($event, $events, true)])->all(),
            'daily_digest' => $request->boolean('daily_digest'),
            'digest_time' => $validated['digest_time'],
        ]);
    }

    private function updateNetwork(Request $request): void
    {
        $validated = $request->validate([
            'provider' => ['required', Rule::in(NetworkProviderManager::PROVIDERS)],
            'connection' => ['nullable', 'in:local,cloud'],
            'controller_url' => ['nullable', Rule::requiredIf(fn () => $request->input('provider') === 'unifi' && $request->input('connection', 'local') === 'local'), 'url', 'starts_with:https://'],
            'console_id' => ['nullable', 'string', 'max:200', 'regex:/^[A-Za-z0-9:_-]+$/'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'site_id' => ['nullable', 'string', 'max:120'],
            'api_prefix' => ['nullable', 'string', 'max:120', 'regex:/^\/[A-Za-z0-9\/_-]*$/'],
            'verify_tls' => ['nullable', 'boolean'],
            'mode' => ['required', 'in:read_only,active'],
            'entry_matching' => ['required', 'in:exact,includes_subdomains'],
            'auto_push' => ['nullable', 'boolean'],
            'controller_authoritative' => ['nullable', 'boolean'],
        ]);

        $integration = IntegrationConfig::query()->where('provider', $validated['provider'])->first()
            ?? new IntegrationConfig(['provider' => $validated['provider'], 'name' => $validated['provider'] === 'unifi' ? 'UniFi Network' : 'Simulation']);

        if ($validated['provider'] === 'unifi' && blank($validated['api_key'] ?? null) && blank($integration->credentials['api_key'] ?? null)) {
            throw ValidationException::withMessages(['api_key' => 'Enter the UniFi API key.']);
        }

        IntegrationConfig::query()->whereIn('provider', NetworkProviderManager::PROVIDERS)->where('provider', '!=', $validated['provider'])->update(['enabled' => false]);

        $integration->fill([
            'enabled' => true,
            'mode' => $validated['mode'],
            'configuration' => [
                'connection' => $validated['connection'] ?? 'local',
                'console_id' => $validated['console_id'] ?? null,
                'controller_url' => isset($validated['controller_url']) ? rtrim($validated['controller_url'], '/') : null,
                'site_id' => $validated['site_id'] ?? null,
                'verify_tls' => $request->boolean('verify_tls'),
                'api_prefix' => $validated['api_prefix'] ?? '/proxy/network/integration/v1',
                'entry_matching' => $validated['entry_matching'],
                'auto_push' => $validated['mode'] === 'active' && $request->boolean('auto_push'),
                'controller_authoritative' => $request->boolean('controller_authoritative', true),
            ],
        ]);
        if (filled($validated['api_key'] ?? null)) {
            $integration->credentials = ['api_key' => $validated['api_key']];
        }
        if ($validated['provider'] === 'unifi' && ($validated['connection'] ?? 'local') === 'cloud' && filled($validated['console_id'] ?? null)) {
            try {
                $integration->configuration = ['console_id' => (new UniFiNetworkProvider($integration))->resolveConsoleId($validated['console_id'])] + $integration->configuration;
            } catch (Throwable $exception) {
                report($exception);
            }
        }
        $integration->save();
    }

    private function updateProfiles(Request $request, PolicyEngine $policy): void
    {
        $validated = $request->validate([
            'profiles' => ['array'],
            'profiles.*.name' => ['required', 'string', 'max:80'],
            'profiles.*.audience' => ['required', Rule::enum(Audience::class)],
            'profiles.*.probe_url' => ['required', 'url', 'max:255'],
            'profiles.*.unifi_network_id' => ['nullable', 'string', 'max:120'],
            'profiles.*.enabled' => ['nullable', 'boolean'],
            'profiles.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'new.name' => ['nullable', 'string', 'max:80'],
            'new.key' => ['nullable', 'required_with:new.name', 'alpha_dash', 'max:40', 'unique:network_profiles,key'],
            'new.audience' => ['nullable', 'required_with:new.name', Rule::enum(Audience::class)],
            'new.probe_url' => ['nullable', 'required_with:new.name', 'url', 'max:255'],
        ]);

        foreach ($validated['profiles'] ?? [] as $id => $values) {
            NetworkProfile::query()->whereKey($id)->update([
                'name' => $values['name'],
                'audience' => $values['audience'],
                'probe_url' => $values['probe_url'],
                'unifi_network_id' => $values['unifi_network_id'] ?? null,
                'enabled' => (bool) ($values['enabled'] ?? false),
                'sort_order' => (int) ($values['sort_order'] ?? 0),
            ]);
        }

        if (filled($validated['new']['name'] ?? null)) {
            NetworkProfile::query()->create([
                'key' => strtolower($validated['new']['key']),
                'name' => $validated['new']['name'],
                'audience' => $validated['new']['audience'],
                'probe_url' => $validated['new']['probe_url'],
                'sort_order' => NetworkProfile::query()->max('sort_order') + 1,
            ]);
            $policy->ensureAudienceLists(Audience::from($validated['new']['audience']));
        }
    }

    private function updateApproval(Request $request, SettingsRepository $settings): void
    {
        $validated = $request->validate([
            'auto_approve_staff' => ['nullable', 'boolean'],
            'students_can_request' => ['nullable', 'boolean'],
            'default_temporary_days' => ['required', 'integer', 'min:1', 'max:365'],
            'categories' => ['required', 'string', 'max:2000'],
        ]);

        $settings->set('approval', [
            'auto_approve_staff' => $request->boolean('auto_approve_staff'),
            'students_can_request' => $request->boolean('students_can_request'),
            'default_temporary_days' => (int) $validated['default_temporary_days'],
            'categories' => $this->commaList($validated['categories'], lower: false),
        ]);
    }

    /** @return list<string> */
    private function commaList(string $value, bool $lower = true): array
    {
        return array_values(array_unique(array_filter(array_map(fn (string $item) => $lower ? strtolower(trim($item)) : trim($item), preg_split('/[,\n]+/', $value) ?: []))));
    }
}
