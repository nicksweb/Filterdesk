<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ListScope;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\BellPeriod;
use App\Models\CalendarPeriod;
use App\Models\FilterPolicy;
use App\Models\IntegrationConfig;
use App\Models\NetworkAssignment;
use App\Models\NetworkProfile;
use App\Models\NetworkType;
use App\Models\PolicyList;
use App\Models\WebhookEndpoint;
use App\Notifications\TestEmailNotification;
use App\Services\AuditLogger;
use App\Services\Auth\UserProvisioningService;
use App\Services\Domains\DomainCategorizer;
use App\Services\Network\NetworkProviderManager;
use App\Services\Network\ReadsContentFilters;
use App\Services\Network\UniFiNetworkProvider;
use App\Services\Notifications\EmailTemplates;
use App\Services\Oidc\OidcDiscoveryService;
use App\Services\Policy\ControllerPusher;
use App\Services\Policy\PolicyEngine;
use App\Services\Policy\ProbePlanner;
use App\Services\Requests\RequestFormSettings;
use App\Services\SchoolCalendar;
use App\Services\SettingsRepository;
use App\Services\Website\ProbeDiagnostics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
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
        'templates' => 'Email templates',
        'network' => 'Network provider',
        'types' => 'Network types',
        'calendar' => 'School calendar',
        'categories' => 'Website categories',
        'profiles' => 'Network profiles',
        'approval' => 'Approval policy',
        'integrations' => 'Integrations',
    ];

    public function show(Request $request, string $section, SettingsRepository $settings, NetworkProviderManager $providers): View
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
            'typeRules' => UserProvisioningService::formatMappingRules($oidc?->configuration['type_rules'] ?? []),
            'departmentRules' => UserProvisioningService::formatMappingRules($oidc?->configuration['department_rules'] ?? []),
            'smtp' => $smtp,
            'smtpHasPassword' => filled($smtp?->credentials['password'] ?? null),
            'network' => $network,
            'networkHasKey' => filled($network?->credentials['api_key'] ?? null),
            'discovery' => session('discovery'),
            'categoryLookup' => $section === 'categories' ? IntegrationConfig::query()->where('provider', DomainCategorizer::PROVIDER)->first() : null,
            'networkTypes' => $section === 'types' ? NetworkType::query()->ordered()->withCount('filterPolicies')->get() : collect(),
            'examLists' => $section === 'types' ? PolicyList::query()->where('purpose', 'exam')->withCount('entries')->get()->keyBy('audience') : collect(),
            'apiTokens' => $section === 'integrations' ? ApiToken::query()->with('user')->latest()->get() : collect(),
            'webhooks' => $section === 'integrations' ? WebhookEndpoint::query()->orderBy('name')->get() : collect(),
            'calendarPeriods' => $section === 'calendar' ? CalendarPeriod::query()->orderBy('start_date')->get() : collect(),
            'bellPeriods' => $section === 'calendar' ? BellPeriod::query()->orderBy('sort_order')->orderBy('starts_at')->get() : collect(),
            'profiles' => $profiles = NetworkProfile::query()->orderBy('sort_order')->orderBy('id')->get(),
            'diagnostics' => $diagnostics = $section === 'profiles' ? collect(app(ProbeDiagnostics::class)->forAll($profiles, $request->boolean('refresh'))) : collect(),
            'identities' => $diagnostics->map(fn (array $result) => $result['identity'] ? ['ok' => true, 'gateway' => $result['identity']['gateway'] ?? null] : ['ok' => false]),
            'expectedGateways' => $section === 'profiles' ? NetworkAssignment::query()->whereIn('provider_network_id', $profiles->pluck('unifi_network_id')->filter())->get()
                ->mapWithKeys(fn (NetworkAssignment $network) => [$network->provider_network_id => app(ProbePlanner::class)->subnet($network->subnet)['gateway'] ?? null]) : collect(),
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
            'types' => $this->updateTypes($request, $policy),
            'categories' => $this->updateCategories($request),
            'calendar' => $this->updateCalendar($request),
            'integrations' => null,
            'templates' => $this->updateTemplates($request, $settings),
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
            'app_name' => ['nullable', 'string', 'max:60'],
            'brand_mark' => ['nullable', 'string', 'max:3'],
            'icon' => ['nullable', 'file', 'max:512', 'mimetypes:image/png,image/jpeg,image/webp,image/svg+xml,image/x-icon,image/vnd.microsoft.icon'],
            'remove_icon' => ['nullable', 'boolean'],
        ], ['icon.mimetypes' => 'Use a PNG, JPEG, WebP, SVG or ICO image.', 'icon.max' => 'The icon must be 512 KB or smaller.']);

        $branding = (array) $settings->get('branding', []);
        $branding['app_name'] = trim((string) ($validated['app_name'] ?? ''));
        $branding['mark'] = trim((string) ($validated['brand_mark'] ?? ''));
        $branding['project_link'] = $request->boolean('project_link', true);
        if ($request->boolean('remove_icon')) {
            unset($branding['icon_data'], $branding['icon_mime']);
        }
        if ($request->hasFile('icon')) {
            $branding['icon_data'] = base64_encode((string) file_get_contents($request->file('icon')->getRealPath()));
            $branding['icon_mime'] = $request->file('icon')->getMimeType();
        }
        $settings->set('branding', $branding);

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
            'type_rules' => ['nullable', 'string', 'max:5000'],
            'department_rules' => ['nullable', 'string', 'max:5000'],
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
                'type_rules' => $this->typeRules($validated['type_rules'] ?? ''),
                'department_rules' => UserProvisioningService::parseMappingRules($validated['department_rules'] ?? '', 'department_rules'),
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
            'mail_verify_tls' => ['nullable', 'boolean'],
            'mail_username' => ['nullable', 'string', 'max:255'],
            'mail_password' => ['nullable', 'string', 'max:1000'],
            'mail_from_address' => ['nullable', 'email'],
            'mail_from_name' => ['nullable', 'string', 'max:120'],
            'it_notification_address' => ['required', 'email'],
            'events' => ['nullable', 'array'],
            'events.*' => ['in:new_request,requester_reply,assignment,filter_sync'],
            'requester_events' => ['nullable', 'array'],
            'requester_events.*' => ['in:submitted,expiry_reminder,expired'],
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
                'verify_tls' => $request->boolean('mail_verify_tls', true),
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
            'requester' => collect(['submitted', 'expiry_reminder', 'expired'])->mapWithKeys(fn (string $event) => [$event => in_array($event, $validated['requester_events'] ?? [], true)])->all(),
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
            'site_id' => ['nullable', 'string', 'max:120', 'different:console_id'],
            'api_prefix' => ['nullable', 'string', 'max:120', 'regex:/^\/[A-Za-z0-9\/_-]*$/'],
            'verify_tls' => ['nullable', 'boolean'],
            'mode' => ['required', 'in:read_only,active'],
            'entry_matching' => ['required', 'in:exact,includes_subdomains'],
            'auto_push' => ['nullable', 'boolean'],
            'controller_authoritative' => ['nullable', 'boolean'],
            'max_filter_entries' => ['nullable', 'integer', 'min:50', 'max:'.ControllerPusher::DEFAULT_MAX_ENTRIES],
            'max_automatic_changes' => ['nullable', 'integer', 'min:10', 'max:'.ControllerPusher::DEFAULT_MAX_ENTRIES],
        ], ['site_id.different' => 'That is the console ID. Leave Site ID blank (the first site is used), or enter the site\'s ID or short name such as "default".']);

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
                'max_filter_entries' => (int) ($validated['max_filter_entries'] ?? ControllerPusher::DEFAULT_MAX_ENTRIES),
                'max_automatic_changes' => (int) ($validated['max_automatic_changes'] ?? ControllerPusher::AUTOMATIC_CHANGE_LIMIT),
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
            'profiles.*.audience' => ['required', Rule::exists('network_types', 'key')],
            'profiles.*.probe_url' => ['required', 'url', 'max:255'],
            'profiles.*.unifi_network_id' => ['nullable', 'string', 'max:120'],
            'profiles.*.enabled' => ['nullable', 'boolean'],
            'profiles.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'new.name' => ['nullable', 'string', 'max:80'],
            'new.key' => ['nullable', 'required_with:new.name', 'alpha_dash', 'max:40', 'unique:network_profiles,key'],
            'new.audience' => ['nullable', 'required_with:new.name', Rule::exists('network_types', 'key')],
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
                'exam_mode' => $values['exam_mode'] ?? $type->exam_mode ?? 'off',
            ]);
            // Exam mode needs somewhere to put the sites it blocks.
            if ($type->exam_mode !== 'off') {
                $policy->examList($type->key);
            }
        }

        if (filled($validated['new']['name'] ?? null)) {
            NetworkProfile::query()->create([
                'key' => strtolower($validated['new']['key']),
                'name' => $validated['new']['name'],
                'audience' => $validated['new']['audience'],
                'probe_url' => $validated['new']['probe_url'],
                'sort_order' => NetworkProfile::query()->max('sort_order') + 1,
            ]);
            $policy->ensureAudienceLists($validated['new']['audience']);
        }
    }

    /**
     * Network types: rename, describe, reorder, choose which can be requested
     * and auto-approved, add new ones, and delete unused ones. Keys never change.
     */
    private function updateTypes(Request $request, PolicyEngine $policy): void
    {
        $validated = $request->validate([
            'types' => ['array'],
            'types.*.name' => ['required', 'string', 'max:80'],
            'types.*.description' => ['nullable', 'string', 'max:255'],
            'types.*.requestable' => ['nullable', 'boolean'],
            'types.*.auto_approve' => ['nullable', 'boolean'],
            'types.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'types.*.delete' => ['nullable', 'boolean'],
            'types.*.exam_mode' => ['nullable', Rule::in(array_keys(NetworkType::EXAM_MODES))],
            'new.name' => ['nullable', 'string', 'max:80'],
            'new.description' => ['nullable', 'string', 'max:255'],
        ]);

        foreach ($validated['types'] ?? [] as $id => $values) {
            $type = NetworkType::query()->find($id);
            if (! $type) {
                continue;
            }
            if (! empty($values['delete'])) {
                if ($type->inUse()) {
                    throw ValidationException::withMessages(["types.{$id}.delete" => "{$type->name} is still used by a policy, list or probe. Move those to another type first."]);
                }
                PolicyList::query()->where('scope', ListScope::Audience)->where('audience', $type->key)->whereDoesntHave('entries')->delete();
                $type->delete();

                continue;
            }
            $type->update([
                'name' => $values['name'], 'description' => $values['description'] ?? null,
                'requestable' => (bool) ($values['requestable'] ?? false), 'auto_approve' => (bool) ($values['auto_approve'] ?? false),
                'sort_order' => (int) ($values['sort_order'] ?? 0),
                'exam_mode' => $values['exam_mode'] ?? $type->exam_mode ?? 'off',
            ]);
            // Exam mode needs somewhere to put the sites it blocks.
            if ($type->exam_mode !== 'off') {
                $policy->examList($type->key);
            }
        }
        if (NetworkType::query()->where('requestable', true)->doesntExist()) {
            throw ValidationException::withMessages(['types' => 'At least one network type must be requestable, or nobody can submit a request.']);
        }

        if (filled($validated['new']['name'] ?? null)) {
            $key = Str::slug($validated['new']['name'], '_') ?: 'type';
            $base = $key;
            for ($suffix = 2; NetworkType::query()->where('key', $key)->exists(); $suffix++) {
                $key = "{$base}_{$suffix}";
            }
            NetworkType::query()->create([
                'key' => $key, 'name' => $validated['new']['name'], 'description' => $validated['new']['description'] ?? null,
                'requestable' => true, 'auto_approve' => false, 'sort_order' => (int) NetworkType::query()->max('sort_order') + 1,
            ]);
            $policy->ensureAudienceLists($key);
        }
        NetworkType::flushCache();
    }

    /**
     * Terms, holidays and exam periods (dates) and bell times. Rows can be
     * edited, added (the blank "new" row) or deleted.
     */
    private function updateCalendar(Request $request): void
    {
        $validated = $request->validate([
            'periods' => ['array'],
            'periods.*.name' => ['required', 'string', 'max:80'],
            'periods.*.kind' => ['required', Rule::in(array_keys(CalendarPeriod::KINDS))],
            'periods.*.start_date' => ['required', 'date'],
            'periods.*.end_date' => ['required', 'date', 'after_or_equal:periods.*.start_date'],
            'periods.*.delete' => ['nullable', 'boolean'],
            'new_period.name' => ['nullable', 'string', 'max:80'],
            'new_period.kind' => ['nullable', Rule::in(array_keys(CalendarPeriod::KINDS))],
            'new_period.start_date' => ['nullable', 'required_with:new_period.name', 'date'],
            'new_period.end_date' => ['nullable', 'required_with:new_period.name', 'date', 'after_or_equal:new_period.start_date'],
            'bells' => ['array'],
            'bells.*.name' => ['required', 'string', 'max:60'],
            'bells.*.starts_at' => ['required', 'date_format:H:i'],
            'bells.*.ends_at' => ['required', 'date_format:H:i', 'after:bells.*.starts_at'],
            'bells.*.delete' => ['nullable', 'boolean'],
            'new_bell.name' => ['nullable', 'string', 'max:60'],
            'new_bell.starts_at' => ['nullable', 'required_with:new_bell.name', 'date_format:H:i'],
            'new_bell.ends_at' => ['nullable', 'required_with:new_bell.name', 'date_format:H:i', 'after:new_bell.starts_at'],
        ], ['periods.*.end_date.after_or_equal' => 'Each period must end on or after its start.', 'bells.*.ends_at.after' => 'Each period must end after it starts.']);

        foreach ($validated['periods'] ?? [] as $id => $values) {
            $period = CalendarPeriod::query()->find($id);
            if ($period) {
                ! empty($values['delete']) ? $period->delete() : $period->update(Arr::only($values, ['name', 'kind', 'start_date', 'end_date']));
            }
        }
        if (filled($validated['new_period']['name'] ?? null)) {
            CalendarPeriod::query()->create(['kind' => $validated['new_period']['kind'] ?? 'term'] + Arr::only($validated['new_period'], ['name', 'start_date', 'end_date']));
        }
        foreach ($validated['bells'] ?? [] as $id => $values) {
            $bell = BellPeriod::query()->find($id);
            if ($bell) {
                ! empty($values['delete']) ? $bell->delete() : $bell->update(Arr::only($values, ['name', 'starts_at', 'ends_at']));
            }
        }
        if (filled($validated['new_bell']['name'] ?? null)) {
            BellPeriod::query()->create(Arr::only($validated['new_bell'], ['name', 'starts_at', 'ends_at']) + ['sort_order' => (int) BellPeriod::query()->max('sort_order') + 1]);
        }
        app(SchoolCalendar::class)->forget();
    }

    /** Email wording. Blank fields, or "reset", fall back to the built-in text. */
    private function updateTemplates(Request $request, SettingsRepository $settings): void
    {
        $keys = array_keys(EmailTemplates::DEFINITIONS);
        $validated = $request->validate([
            'templates' => ['array'],
            'templates.*.subject' => ['nullable', 'string', 'max:200'],
            'templates.*.greeting' => ['nullable', 'string', 'max:200'],
            'templates.*.body' => ['nullable', 'string', 'max:5000'],
            'templates.*.action' => ['nullable', 'string', 'max:60'],
            'templates.*.reset' => ['nullable', 'boolean'],
        ]);
        $stored = (array) $settings->get('email_templates', []);
        foreach ($validated['templates'] ?? [] as $key => $fields) {
            if (! in_array($key, $keys, true)) {
                continue;
            }
            if (! empty($fields['reset'])) {
                unset($stored[$key]);

                continue;
            }
            $defaults = EmailTemplates::DEFINITIONS[$key];
            // Store only what differs from the default, so improvements to the defaults still reach untouched fields.
            $custom = collect(EmailTemplates::FIELDS)->mapWithKeys(fn (string $field) => [$field => trim((string) ($fields[$field] ?? ''))])
                ->filter(fn (string $value, string $field) => $value !== '' && str_replace("\r\n", "\n", $value) !== $defaults[$field])->all();
            if ($custom === []) {
                unset($stored[$key]);
            } else {
                $stored[$key] = $custom;
            }
        }
        $settings->set('email_templates', $stored);
    }

    /** Website category lookup: off, Cloudflare Radar (token) or Cloudflare Intel (token and account id). Token is write-only. */
    private function updateCategories(Request $request): void
    {
        $validated = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'source' => ['required', 'in:auto,radar,intel'],
            'token' => ['nullable', 'string', 'max:200'],
            'account_id' => ['nullable', 'required_if:source,intel', 'string', 'max:32', 'regex:/^[a-f0-9]{32}$/i'],
        ], ['account_id.regex' => 'The account ID is the 32-character hex id in the Cloudflare dashboard URL.']);

        $config = IntegrationConfig::query()->firstOrNew(['provider' => DomainCategorizer::PROVIDER], ['name' => 'Website categories', 'mode' => 'read_only']);
        $config->fill(['enabled' => $request->boolean('enabled'), 'configuration' => ['source' => $validated['source'], 'account_id' => $validated['account_id'] ?? null]]);
        if (filled($validated['token'] ?? null)) {
            $config->credentials = ['token' => $validated['token']];
        }
        if ($config->enabled && blank($config->credentials['token'] ?? null)) {
            throw ValidationException::withMessages(['token' => 'Enter a Cloudflare API token to turn category lookups on.']);
        }
        $config->save();
    }

    /** Look up one domain from the settings page, bypassing the cache. */
    public function testCategories(Request $request, DomainCategorizer $categorizer): RedirectResponse
    {
        $validated = $request->validate(['domain' => ['required', 'string', 'max:253', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i']]);
        if (! $categorizer->enabled()) {
            return back()->with('error', 'Turn category lookups on and save a token first.');
        }
        $result = $categorizer->lookup(strtolower($validated['domain']), refresh: true);

        return back()->with($result['ok'] ? 'success' : 'error', $result['ok']
            ? "{$validated['domain']}: ".($result['categories'] ? implode(', ', $result['categories']) : ($result['message'] ?? 'no categories')).($result['unifi'] ? ' · UniFi: '.collect($result['unifi'])->pluck('label')->join(', ') : '')
            : $result['message']);
    }

    private function updateApproval(Request $request, SettingsRepository $settings): void
    {
        $validated = $request->validate([
            'auto_approve_staff' => ['nullable', 'boolean'],
            'students_can_request' => ['nullable', 'boolean'],
            'default_temporary_days' => ['required', 'integer', 'min:1', 'max:365'],
            'two_person_site_wide' => ['nullable', 'boolean'],
            'two_person_override' => ['nullable', 'boolean'],
            'guide.enabled' => ['nullable', 'boolean'],
            'guide.students' => ['nullable', 'boolean'],
            'guide.title' => ['nullable', 'string', 'max:120'],
            'guide.intro' => ['nullable', 'string', 'max:600'],
            'guide.steps' => ['nullable', 'string', 'max:3000'],
            'review_months' => ['nullable', 'integer', 'min:1', 'max:36'],
            'grace_days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'sensitive_categories' => ['nullable', 'string', 'max:4000'],
            'categories' => ['required', 'string', 'max:2000'],
            'window_lead_minutes' => ['nullable', 'integer', 'min:0', 'max:60'],
            'window_lag_minutes' => ['nullable', 'integer', 'min:0', 'max:60'],
            'schedule_margin_minutes' => ['nullable', 'integer', 'min:0', 'max:180'],
            'form.access_label' => ['nullable', 'string', 'max:120'],
            'form.block_label' => ['nullable', 'string', 'max:120'],
            'form.access_hint' => ['nullable', 'string', 'max:300'],
            'form.block_hint' => ['nullable', 'string', 'max:300'],
            'form.staff_required' => ['nullable', 'boolean'],
            'form.student_required' => ['nullable', 'boolean'],
            'form.min_length' => ['nullable', 'integer', 'min:0', 'max:500'],
            'form.student_categories' => ['nullable', 'string', 'max:2000'],
        ]);
        $defaults = RequestFormSettings::DEFAULTS;
        $form = $validated['form'] ?? [];
        $settings->set('request_form', [
            'access_label' => $form['access_label'] ?? $defaults['access_label'], 'block_label' => $form['block_label'] ?? $defaults['block_label'],
            'access_hint' => $form['access_hint'] ?? '', 'block_hint' => $form['block_hint'] ?? '',
            'staff_required' => $request->boolean('form.staff_required', $defaults['staff_required']), 'student_required' => $request->boolean('form.student_required', $defaults['student_required']),
            'min_length' => (int) ($form['min_length'] ?? $defaults['min_length']),
            'student_categories' => filled($form['student_categories'] ?? null) ? $this->commaList($form['student_categories'], lower: false) : $defaults['student_categories'],
        ]);

        $settings->set('approval', [
            'auto_approve_staff' => $request->boolean('auto_approve_staff'),
            'students_can_request' => $request->boolean('students_can_request'),
            'default_temporary_days' => (int) $validated['default_temporary_days'],
            'categories' => $this->commaList($validated['categories'], lower: false),
            'window_lead_minutes' => (int) ($validated['window_lead_minutes'] ?? 5),
            'window_lag_minutes' => (int) ($validated['window_lag_minutes'] ?? 5),
            'schedule_margin_minutes' => (int) ($validated['schedule_margin_minutes'] ?? 60),
            'two_person_site_wide' => $request->boolean('two_person_site_wide', true),
            'two_person_override' => $request->boolean('two_person_override', true),
            'sensitive_categories' => $this->commaList((string) ($validated['sensitive_categories'] ?? ''), lower: false),
        ]);
        $settings->set('access_guide', [
            'enabled' => $request->boolean('guide.enabled', true),
            'students' => $request->boolean('guide.students'),
            'title' => trim((string) ($validated['guide']['title'] ?? '')),
            'intro' => trim((string) ($validated['guide']['intro'] ?? '')),
            'steps' => trim((string) ($validated['guide']['steps'] ?? '')),
        ]);
        $settings->set('governance', [
            'review_months' => (int) ($validated['review_months'] ?? 12),
            'grace_days' => (int) ($validated['grace_days'] ?? 14),
        ]);
    }

    /**
     * Network type rules, each naming existing type keys.
     *
     * @return list<array{type: string, value: string, target: string}>
     */
    private function typeRules(string $text): array
    {
        $rules = UserProvisioningService::parseMappingRules($text, 'type_rules');
        $keys = NetworkType::keys();
        foreach ($rules as $rule) {
            $unknown = array_diff(array_map('trim', explode(',', $rule['target'])), $keys);
            if ($unknown !== []) {
                throw ValidationException::withMessages(['type_rules' => 'Unknown network type '.implode(', ', $unknown).'. Use the keys under Settings → Network types: '.implode(', ', $keys).'.']);
            }
        }

        return $rules;
    }

    /** @return list<string> */
    private function commaList(string $value, bool $lower = true): array
    {
        return array_values(array_unique(array_filter(array_map(fn (string $item) => $lower ? strtolower(trim($item)) : trim($item), preg_split('/[,\n]+/', $value) ?: []))));
    }
}
