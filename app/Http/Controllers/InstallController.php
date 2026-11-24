<?php

namespace App\Http\Controllers;

use App\Models\IntegrationConfig;
use App\Models\NetworkProfile;
use App\Models\NetworkType;
use App\Models\User;
use App\Services\Approval\TwoPersonRule;
use App\Services\Auth\UserProvisioningService;
use App\Services\Domains\DomainCategorizer;
use App\Services\Network\UniFiNetworkProvider;
use App\Services\Oidc\OidcDiscoveryService;
use App\Services\Oidc\OidcProviderHints;
use App\Services\Policy\PolicyBackfill;
use App\Services\Policy\PolicyEngine;
use App\Services\SettingsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
            'checks' => $step === 1 ? $this->systemChecks($request) : [],
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
                'role_rules' => ['nullable', 'string', 'max:5000'],
                'default_role' => ['nullable', 'in:student,teacher,deny'],
                'oidc_scopes' => ['nullable', 'string', 'max:500'],
                'oidc_group_claims' => ['nullable', 'string', 'max:300'],
                'sync_roles' => ['nullable', 'boolean'],
                'emergency_local_login' => ['nullable', 'boolean'],
            ]),
            4 => $request->validate([
                'unifi_enabled' => ['nullable', 'boolean'],
                'unifi_connection' => ['nullable', 'in:local,cloud'],
                'unifi_controller_url' => ['nullable', Rule::requiredIf(fn () => $request->boolean('unifi_enabled') && $request->input('unifi_connection', 'local') === 'local'), 'url', 'starts_with:https://'],
                'unifi_console_id' => ['nullable', 'string', 'max:200', 'regex:/^[A-Za-z0-9:_-]+$/'],
                'unifi_api_key' => ['nullable', Rule::requiredIf(fn () => $request->boolean('unifi_enabled') && blank($data['unifi_api_key'] ?? null)), 'string'],
                'unifi_site_id' => ['nullable', 'string', 'max:120', 'different:unifi_console_id'],
                'unifi_verify_tls' => ['nullable', 'boolean'],
                'unifi_mode' => ['required', 'in:read_only,active'],
                'entry_matching' => ['nullable', 'in:exact,includes_subdomains'],
            ]),
            5 => $request->validate([
                'student_probe_url' => ['required', 'url'],
                'staff_probe_url' => ['required', 'url'],
                'student_network_id' => ['nullable', 'string'],
                'staff_network_id' => ['nullable', 'string'],
                'networks' => ['nullable', 'array', 'max:'.self::EXTRA_NETWORK_ROWS],
                'networks.*.name' => ['nullable', 'string', 'max:60'],
                'networks.*.type' => ['nullable', 'required_with:networks.*.name', 'string', 'max:40'],
                'networks.*.new_type' => ['nullable', 'string', 'max:60', 'required_if:networks.*.type,__new'],
                'networks.*.vlan' => ['nullable', 'integer', 'min:1', 'max:4094'],
            ], ['networks.*.new_type.required_if' => 'Name the new network type.', 'networks.*.vlan.max' => 'A VLAN is a number from 1 to 4094.']),
            6 => $request->validate([
                'mail_host' => ['nullable', 'string'], 'mail_port' => ['nullable', 'integer'], 'mail_encryption' => ['nullable', 'in:tls,ssl,none'],
                'mail_username' => ['nullable', 'string'], 'mail_password' => ['nullable', 'string'],
                'mail_from_address' => ['nullable', 'email'], 'it_notification_address' => ['required', 'email'],
            ]),
            7 => $request->validate([
                'auto_approve_staff' => ['nullable', 'boolean'],
                'students_can_request' => ['nullable', 'boolean'],
                'default_temporary_days' => ['required', 'integer', 'min:1', 'max:365'],
                'categories' => ['required', 'string'],
                'two_person_site_wide' => ['nullable', 'boolean'],
                'two_person_override' => ['nullable', 'boolean'],
                'sensitive_categories' => ['nullable', 'string', 'max:4000'],
                'review_months' => ['nullable', 'integer', 'min:1', 'max:36'],
                'grace_days' => ['nullable', 'integer', 'min:1', 'max:90'],
            ] + self::CATEGORY_RULES, ['categories_account_id.regex' => 'The account ID is the 32-character hex id in the Cloudflare dashboard URL.']),
            8 => [],
            default => abort(404),
        };

        // A blank key keeps the one entered before (for example before Test connection).
        if ($step === 4 && blank($validated['unifi_api_key'] ?? null)) {
            unset($validated['unifi_api_key']);
        }
        if ($step === 7) {
            $validated['categories_enabled'] = $request->boolean('categories_enabled');
            if (blank($validated['categories_token'] ?? null)) {
                unset($validated['categories_token']);
            }
            if ($validated['categories_enabled'] && blank($validated['categories_token'] ?? $request->session()->get('installation.categories_token'))) {
                return back()->withInput()->withErrors(['categories_token' => 'Enter a Cloudflare API token to turn website categories on, or untick it.']);
            }
        }

        if ($step === 3 && filled($validated['role_rules'] ?? null)) {
            UserProvisioningService::parseRules($validated['role_rules']);
        }

        if ($step === 3) {
            unset($validated['admin_password_confirmation']);
            $validated['admin_password'] = Hash::make($validated['admin_password']);
        }

        foreach (['oidc_enabled', 'sync_roles', 'emergency_local_login', 'unifi_enabled', 'unifi_verify_tls', 'auto_approve_staff', 'students_can_request', 'two_person_site_wide', 'two_person_override'] as $boolean) {
            if (array_key_exists($boolean, $request->all())) {
                $validated[$boolean] = $request->boolean($boolean);
            }
        }

        $data = array_merge($data, $validated);
        $request->session()->put('installation', $data);

        $notice = null;
        if ($step === 3 && ($data['oidc_enabled'] ?? false)) {
            $discovery = app(OidcDiscoveryService::class)->check($data['oidc_issuer'], 'oidc_issuer');
            // A provider that only sends groups with its own scope (CloudWork ID): ask for it, or no group: rule can match.
            $hints = app(OidcProviderHints::class);
            $rules = UserProvisioningService::parseRules($data['role_rules'] ?? '');
            $scopes = OidcTestController::words($data['oidc_scopes'] ?? '');
            $hint = $hints->for($data['oidc_issuer'], $discovery);
            $missing = array_values(array_diff($hint['scopes'], $scopes));
            if ($hint['provider'] !== null && $missing !== [] && collect($rules)->contains(fn (array $rule) => $rule['type'] === 'group')) {
                $scopes = [...$scopes, ...$missing];
                $data['oidc_scopes'] = implode(' ', $scopes);
                $notice = 'Added the scope '.implode(' ', $missing).' under Extra scopes: '.$hint['note'];
            }
            $data['sso_warning'] = $hints->warning($data['oidc_issuer'], $rules, $scopes, $discovery);
            $request->session()->put('installation', $data);
        }

        if ($step === 8) {
            $this->complete($data);
            $request->session()->forget('installation');

            return redirect()->route('login')->with('success', 'Installation complete. Sign in with the administrator account; the home page lists what to finish next'.(($data['unifi_enabled'] ?? false) ? ', starting with Guided setup to bring your UniFi filters in.' : '.'));
        }

        return redirect()->route('install.show', $step + 1)->with('info', $notice);
    }

    /**
     * Test the UniFi connection from the wizard, with what has been typed so
     * far (nothing is saved but the form): the consoles a Site Manager key
     * can see, to pick the one to manage, then its sites, networks and
     * content filters, so you can tell it is the right one.
     */
    /** Website category lookups (optional), as in Settings → Website categories. */
    private const CATEGORY_RULES = [
        'categories_enabled' => ['nullable', 'boolean'],
        'categories_source' => ['nullable', 'in:auto,radar,intel'],
        'categories_token' => ['nullable', 'string', 'max:200'],
        'categories_account_id' => ['nullable', 'required_if:categories_source,intel', 'string', 'max:32', 'regex:/^[a-f0-9]{32}$/i'],
    ];

    /** Step 7: try the Cloudflare token on one well-known domain before it is saved. */
    public function testCategories(Request $request): RedirectResponse
    {
        $data = $request->session()->get('installation', []);
        $validated = $request->validate(self::CATEGORY_RULES, ['categories_account_id.regex' => 'The account ID is the 32-character hex id in the Cloudflare dashboard URL.']);
        $token = filled($validated['categories_token'] ?? null) ? $validated['categories_token'] : ($data['categories_token'] ?? null);
        if (blank($token)) {
            return back()->withInput()->withErrors(['categories_token' => 'Enter the Cloudflare API token to test it.']);
        }
        $data = array_merge($data, array_filter($validated, fn ($value) => $value !== null), ['categories_enabled' => true, 'categories_token' => $token]);

        $categorizer = app(DomainCategorizer::class)->using(new IntegrationConfig([
            'provider' => DomainCategorizer::PROVIDER, 'mode' => 'read_only', 'enabled' => true,
            'configuration' => ['source' => $data['categories_source'] ?? 'auto', 'account_id' => $data['categories_account_id'] ?? null],
            'credentials' => ['token' => $token],
        ]));
        $result = rescue(fn () => $categorizer->lookup('wikipedia.org', refresh: true), ['ok' => false, 'categories' => [], 'message' => 'The lookup failed.'], false);
        $data['categories_test'] = [
            'ok' => $result['ok'] && $result['categories'] !== [],
            'message' => $result['ok'] && $result['categories'] !== [] ? 'wikipedia.org: '.implode(', ', $result['categories']) : ($result['message'] ?? 'No categories came back.'),
        ];
        $request->session()->put('installation', $data);

        // Keep what was typed in the rest of the step.
        return redirect()->route('install.show', 7)->withInput($request->except('categories_token', '_token'));
    }

    public function testUnifi(Request $request): RedirectResponse
    {
        $data = $request->session()->get('installation', []);
        $validated = $request->validate([
            'unifi_connection' => ['required', 'in:local,cloud'],
            'unifi_controller_url' => ['nullable', 'required_if:unifi_connection,local', 'url', 'starts_with:https://'],
            'unifi_console_id' => ['nullable', 'string', 'max:200', 'regex:/^[A-Za-z0-9:_-]+$/'],
            'unifi_api_key' => ['nullable', 'string', 'max:500'],
            'unifi_site_id' => ['nullable', 'string', 'max:120'],
            'unifi_verify_tls' => ['nullable', 'boolean'],
            'unifi_mode' => ['nullable', 'in:read_only,active'],
            'entry_matching' => ['nullable', 'in:exact,includes_subdomains'],
        ]);
        $key = filled($validated['unifi_api_key'] ?? null) ? $validated['unifi_api_key'] : ($data['unifi_api_key'] ?? null);
        if (blank($key)) {
            return back()->withErrors(['unifi_api_key' => 'Enter the API key to test the connection.']);
        }
        $data = array_merge($data, array_filter($validated, fn ($value) => $value !== null), ['unifi_enabled' => true, 'unifi_api_key' => $key, 'unifi_verify_tls' => $request->boolean('unifi_verify_tls', true)]);
        $request->session()->put('installation', $data);

        $provider = new UniFiNetworkProvider(new IntegrationConfig([
            'provider' => 'unifi', 'mode' => 'read_only', 'enabled' => true,
            'configuration' => ['connection' => $data['unifi_connection'], 'console_id' => $data['unifi_console_id'] ?? null, 'controller_url' => $data['unifi_controller_url'] ?? null,
                'site_id' => $data['unifi_site_id'] ?? null, 'verify_tls' => (bool) $data['unifi_verify_tls'], 'api_prefix' => '/proxy/network/integration/v1'],
            'credentials' => ['api_key' => $key],
        ]));
        try {
            $test = $provider->test();
            $discovery = $provider->discover($data['unifi_site_id'] ?? null);
            $filters = $test['ok'] ? rescue(fn () => count($provider->contentFilters()), null, false) : null;
        } catch (Throwable $exception) {
            $test = ['ok' => false, 'message' => 'The connection test failed: '.str($exception->getMessage())->limit(200)];
            $discovery = ['consoles' => [], 'sites' => [], 'networks' => []];
            $filters = null;
        }
        $result = ['ok' => $test['ok'], 'message' => $test['message'], 'consoles' => $discovery['consoles'] ?? [], 'sites' => $discovery['sites'] ?? [],
            'networks' => collect($discovery['networks'] ?? [])->map(fn (array $network) => $network['name'].(isset($network['vlan']) && $network['vlan'] ? ' ('.$network['vlan'].')' : ''))->values()->all(),
            'filters' => $filters, 'at' => now()->toIso8601String()];
        $data['unifi_test'] = $result;
        $request->session()->put('installation', $data);

        return redirect()->route('install.show', 4);
    }

    /** @return array<string, array{ok: bool, message: string}> */
    private function systemChecks(Request $request): array
    {
        try {
            DB::select('select 1');
            $database = ['ok' => true, 'message' => 'Database connection is ready.'];
        } catch (Throwable $exception) {
            report($exception);
            $database = ['ok' => false, 'message' => 'Database unavailable. Check DB_* settings and that the migrate service completed.'];
        }

        return [
            'database' => $database,
            'app_key' => ['ok' => filled(config('app.key')), 'message' => filled(config('app.key')) ? 'Application encryption key is configured.' : 'APP_KEY must be generated.'],
            'queue' => ['ok' => config('queue.default') !== 'sync', 'message' => 'Queue driver: '.config('queue.default')],
            'proxy' => $this->proxyCheck($request),
        ];
    }

    /**
     * A reverse proxy that is not in TRUSTED_PROXIES makes every link and
     * stylesheet http:// on an https page, so browsers block the CSS.
     *
     * @return array{ok: bool, message: string}
     */
    private function proxyCheck(Request $request): array
    {
        $forwardedHttps = strtolower((string) $request->server('HTTP_X_FORWARDED_PROTO')) === 'https';
        if ($forwardedHttps && ! $request->isSecure()) {
            $address = (string) $request->server('REMOTE_ADDR');
            // A proxy in Docker connects from its network's address range; suggest that, as the docs do.
            $subnet = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && ! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)
                ? implode('.', array_slice(explode('.', $address), 0, 2)).'.0.0/16' : null;

            return ['ok' => false, 'message' => "Your reverse proxy at {$address} is not trusted, so pages link to http:// stylesheets that browsers block. "
                .($subnet
                    ? "Set TRUSTED_PROXIES={$subnet} in .env if the proxy runs in Docker (its network's subnet; check with docker network inspect -f '{{range .IPAM.Config}}{{.Subnet}}{{end}}' <network>), or TRUSTED_PROXIES={$address} for that one address"
                    : "Set TRUSTED_PROXIES={$address} in .env")
                .', then recreate the containers (docker compose up -d --force-recreate). Also check the proxy forwards to port 8080 (http://filterdesk-web:8080), not 8088.'];
        }

        return ['ok' => true, 'message' => $request->isSecure() ? 'HTTPS reverse proxy is trusted.' : 'Plain HTTP (fine locally; use HTTPS behind a reverse proxy in production).'];
    }

    /** Rows offered on step 5 for networks beyond Students and Staff. */
    public const EXTRA_NETWORK_ROWS = 6;

    /**
     * Networks added on step 5 (guest Wi-Fi, CCTV, a lab…): each becomes a
     * network profile with its own planned probe, switched off until its
     * container runs (Guided setup plans the container on the VLAN). A new
     * type is created; the default policies then give each type a filter policy.
     *
     * @param  array<int, array{name?: ?string, type?: ?string, new_type?: ?string, vlan?: mixed}>  $rows
     */
    private function createExtraNetworks(array $rows): void
    {
        $order = 3;
        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $type = (string) ($row['type'] ?? 'other');
            if ($type === '__new') {
                $typeName = trim((string) ($row['new_type'] ?? '')) ?: $name;
                $type = Str::slug($typeName, '_') ?: 'network';
                NetworkType::ensure($type, $typeName);
            } elseif (! NetworkType::byKey($type)) {
                $type = 'other';
            }

            $key = Str::slug($name, '_') ?: 'network';
            $base = $key;
            for ($suffix = 2; in_array($key, ['student', 'staff'], true) || NetworkProfile::query()->where('key', $key)->exists(); $suffix++) {
                $key = "{$base}_{$suffix}";
            }
            NetworkProfile::query()->create([
                'key' => $key, 'name' => $name, 'audience' => $type, 'enabled' => false, 'sort_order' => $order++,
                // The service Guided setup plans for this profile (ProbePlanner names it probe-<key>).
                'probe_url' => 'http://probe-'.Str::slug($key).':3000',
                'unifi_network_id' => filled($row['vlan'] ?? null) ? (string) (int) $row['vlan'] : null,
            ]);
        }
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
            $settings->set('notifications', [
                'it_address' => strtolower($data['it_notification_address']),
                'events' => ['new_request' => true, 'requester_reply' => true, 'assignment' => true],
                'daily_digest' => true,
                'digest_time' => '07:30',
            ]);
            $settings->set('approval', [
                'auto_approve_staff' => (bool) ($data['auto_approve_staff'] ?? false),
                'students_can_request' => (bool) ($data['students_can_request'] ?? false),
                'default_temporary_days' => (int) $data['default_temporary_days'],
                'categories' => array_values(array_filter(array_map('trim', explode(',', $data['categories'])))),
                'two_person_site_wide' => (bool) ($data['two_person_site_wide'] ?? true),
                'two_person_override' => (bool) ($data['two_person_override'] ?? true),
                'sensitive_categories' => isset($data['sensitive_categories'])
                    ? array_values(array_filter(array_map('trim', preg_split('/[,\n]+/', (string) $data['sensitive_categories']) ?: [])))
                    : TwoPersonRule::DEFAULT_SENSITIVE,
            ]);
            $settings->set('governance', [
                'review_months' => (int) ($data['review_months'] ?? 12),
                'grace_days' => (int) ($data['grace_days'] ?? 14),
            ]);

            User::query()->updateOrCreate(['email' => strtolower($data['admin_email'])], [
                'name' => $data['admin_name'], 'password' => $data['admin_password'],
                'role' => 'admin', 'enabled' => true, 'email_verified_at' => now(),
            ]);

            if ($data['oidc_enabled'] ?? false) {
                IntegrationConfig::query()->create([
                    'provider' => 'oidc', 'name' => 'OpenID Connect', 'mode' => 'active', 'enabled' => true,
                    'configuration' => [
                        'issuer' => OidcDiscoveryService::normalise($data['oidc_issuer']), 'client_id' => $data['oidc_client_id'],
                        'allowed_domains' => array_values(array_filter(array_map(fn ($value) => strtolower(trim($value)), explode(',', $data['oidc_allowed_domains'] ?? '')))),
                        'role_rules' => UserProvisioningService::parseRules($data['role_rules'] ?? ''),
                        'default_role' => $data['default_role'] ?? 'teacher',
                        'scopes' => OidcTestController::words($data['oidc_scopes'] ?? ''),
                        'group_claims' => OidcTestController::words($data['oidc_group_claims'] ?? ''),
                        // While rules are still being tuned, roles follow them at every sign-in.
                        'sync_roles' => (bool) ($data['sync_roles'] ?? filled($data['role_rules'] ?? null)),
                    ],
                    'credentials' => ['client_secret' => $data['oidc_client_secret']],
                ]);
            }

            if (! empty($data['mail_host'])) {
                IntegrationConfig::query()->create([
                    'provider' => 'smtp', 'name' => 'Email notifications', 'mode' => 'active', 'enabled' => true,
                    'configuration' => [
                        'host' => $data['mail_host'], 'port' => $data['mail_port'] ?? 587, 'encryption' => $data['mail_encryption'] ?? 'tls', 'verify_tls' => true,
                        'from' => $data['mail_from_address'] ?? $data['it_notification_address'],
                    ],
                    'credentials' => ['username' => $data['mail_username'] ?? null, 'password' => $data['mail_password'] ?? null],
                ]);
            }

            if (($data['categories_enabled'] ?? false) && filled($data['categories_token'] ?? null)) {
                IntegrationConfig::query()->create([
                    'provider' => DomainCategorizer::PROVIDER, 'name' => 'Website categories', 'mode' => 'read_only', 'enabled' => true,
                    'configuration' => ['source' => $data['categories_source'] ?? 'auto', 'account_id' => $data['categories_account_id'] ?? null],
                    'credentials' => ['token' => $data['categories_token']],
                ]);
            }

            if ($data['unifi_enabled'] ?? false) {
                IntegrationConfig::query()->create([
                    'provider' => 'unifi', 'name' => 'UniFi Network', 'mode' => $data['unifi_mode'], 'enabled' => true,
                    'configuration' => [
                        'connection' => $data['unifi_connection'] ?? 'local', 'console_id' => $data['unifi_console_id'] ?? null,
                        'controller_url' => $data['unifi_controller_url'] ?? null, 'site_id' => $data['unifi_site_id'] ?? null,
                        'verify_tls' => (bool) ($data['unifi_verify_tls'] ?? true), 'api_prefix' => '/proxy/network/integration/v1',
                        'entry_matching' => $data['entry_matching'] ?? 'exact',
                    ],
                    'credentials' => ['api_key' => $data['unifi_api_key']],
                ]);
            } else {
                IntegrationConfig::query()->create(['provider' => 'mock', 'name' => 'Simulation', 'mode' => 'read_only', 'enabled' => true]);
            }

            NetworkProfile::query()->create(['key' => 'student', 'name' => 'Student Access', 'audience' => 'student', 'sort_order' => 1, 'probe_url' => $data['student_probe_url'], 'unifi_network_id' => $data['student_network_id'] ?? null]);
            NetworkProfile::query()->create(['key' => 'staff', 'name' => 'Staff Access', 'audience' => 'staff', 'sort_order' => 2, 'probe_url' => $data['staff_probe_url'], 'unifi_network_id' => $data['staff_network_id'] ?? null]);
            $this->createExtraNetworks($data['networks'] ?? []);
            app(PolicyEngine::class)->ensureDefaults();
            $settings->set(config('version.stored_version_key'), config('version.app'));
            $settings->set(PolicyBackfill::WINDOW_FIX_FLAG, true);
            $settings->set('installation.completed', true);
        });
    }
}
