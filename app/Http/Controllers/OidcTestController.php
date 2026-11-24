<?php

namespace App\Http\Controllers;

use App\Models\IntegrationConfig;
use App\Services\Auth\UserProvisioningService;
use App\Services\Oidc\OidcClient;
use App\Services\Oidc\OidcDiscoveryService;
use App\Services\Oidc\OidcProviderHints;
use App\Services\SettingsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

/**
 * Test sign-in: go to the identity provider and back with the settings as
 * they are (in the installer, before anything is saved), and show what the
 * provider sent and the role the rules would give. No user is created and
 * nobody is signed in. It uses the normal redirect URI, so nothing extra
 * needs registering with the provider.
 */
class OidcTestController extends Controller
{
    public const SESSION_KEY = 'oidc.test';

    public const RESULT_KEY = 'oidc_test_result';

    /** From the installer's sign-in step, with what has been typed so far. */
    public function install(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'oidc_issuer' => ['required', 'url', 'starts_with:https://'],
            'oidc_client_id' => ['required', 'string', 'max:255'],
            'oidc_client_secret' => ['nullable', 'string', 'max:1000'],
            'oidc_scopes' => ['nullable', 'string', 'max:500'],
            'oidc_group_claims' => ['nullable', 'string', 'max:300'],
            'oidc_allowed_domains' => ['nullable', 'string', 'max:1000'],
            'role_rules' => ['nullable', 'string', 'max:5000'],
            'default_role' => ['nullable', 'in:student,teacher,deny'],
            'admin_name' => ['nullable', 'string', 'max:120'],
            'admin_email' => ['nullable', 'email'],
        ]);
        $data = $request->session()->get('installation', []);
        $secret = filled($validated['oidc_client_secret'] ?? null) ? $validated['oidc_client_secret'] : ($data['oidc_client_secret'] ?? null);
        if (blank($secret)) {
            return back()->withInput()->withErrors(['oidc_client_secret' => 'Enter the client secret to test sign-in.']);
        }
        $rules = UserProvisioningService::parseRules($validated['role_rules'] ?? '');
        // Keep what was typed, so the form is filled in again on the way back.
        $request->session()->put('installation', array_merge($data, array_filter($validated, fn ($value) => $value !== null), ['oidc_enabled' => true, 'oidc_client_secret' => $secret]));
        app(OidcDiscoveryService::class)->check($validated['oidc_issuer'], 'oidc_issuer');

        return $this->start($request, [
            'issuer' => OidcDiscoveryService::normalise($validated['oidc_issuer']),
            'client_id' => $validated['oidc_client_id'],
            'scopes' => self::words($validated['oidc_scopes'] ?? ''),
            'group_claims' => self::words($validated['oidc_group_claims'] ?? ''),
            'role_rules' => $rules,
            'default_role' => $validated['default_role'] ?? 'teacher',
        ], $secret, route('install.show', 3));
    }

    /** From Settings → Authentication, with the saved settings. */
    public function settings(Request $request): RedirectResponse
    {
        $oidc = IntegrationConfig::query()->where('provider', 'oidc')->first();
        if (! $oidc || blank($oidc->configuration['issuer'] ?? null) || blank($oidc->credentials['client_secret'] ?? null)) {
            return back()->with('error', 'Save the issuer, client ID and client secret first.');
        }

        return $this->start($request, $oidc->configuration, (string) $oidc->credentials['client_secret'], route('admin.settings.show', 'authentication'));
    }

    /**
     * Back from the provider: read the claims and work out the role, without
     * creating a user or a session.
     */
    public function finish(Request $request, OidcClient $client, UserProvisioningService $users, OidcProviderHints $hints): RedirectResponse
    {
        $token = (string) $request->session()->pull(self::SESSION_KEY);
        $pending = Cache::pull('oidc.test.'.$token);
        $state = (string) $request->session()->pull('oidc.state');
        $pkce = $request->session()->pull('oidc.pkce');
        if (! is_array($pending)) {
            return (app(SettingsRepository::class)->installed() ? redirect()->route('login') : redirect()->route('install.show', 3))
                ->with('error', 'The test sign-in expired. Try again.');
        }
        $configuration = $pending['configuration'];
        $back = redirect()->to($pending['return']);
        if (! hash_equals($state, (string) $request->query('state'))) {
            return $back->with(self::RESULT_KEY, ['ok' => false, 'error' => 'The test sign-in expired or was started twice. Try again.']);
        }

        try {
            if (filled($request->query('error'))) {
                throw new \RuntimeException('The identity provider returned '.$request->query('error').': '.str((string) $request->query('error_description'))->limit(300));
            }
            $client = $client->using($this->integration($configuration, Crypt::decryptString($pending['secret'])));
            $claims = $client->claims($client->exchange((string) $request->query('code'), $pkce));
        } catch (Throwable $exception) {
            return $back->with(self::RESULT_KEY, ['ok' => false, 'error' => str($exception->getMessage())->limit(500)->toString()]);
        }

        $role = $users->explainRole($claims, $configuration);
        $warning = null;
        if ($claims['groups'] === []) {
            $hint = $hints->for((string) $configuration['issuer']);
            $warning = 'No groups or roles came back, so group: rules cannot match this account.'.($hint['note'] ? ' '.$hint['note'] : ' Check the provider sends a groups claim, and add any scope it needs under Extra scopes.');
        }

        return $back->with(self::RESULT_KEY, [
            'ok' => true, 'claims' => $claims['visible'], 'groups' => $claims['groups'], 'group_claims' => $claims['group_claims'],
            'role' => $role['role'], 'rule' => $role['rule'], 'default_role' => $configuration['default_role'] ?? 'teacher', 'warning' => $warning,
            'domain' => str($claims['email'])->afterLast('@')->toString(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function start(Request $request, array $configuration, string $secret, string $return): RedirectResponse
    {
        try {
            [$url, $state, $pkce] = app(OidcClient::class)->using($this->integration($configuration, $secret))->authorizationUrl();
        } catch (Throwable $exception) {
            return back()->withInput()->with(self::RESULT_KEY, ['ok' => false, 'error' => str($exception->getMessage())->limit(500)->toString()]);
        }
        $token = Str::random(40);
        Cache::put('oidc.test.'.$token, ['configuration' => $configuration, 'secret' => Crypt::encryptString($secret), 'return' => $return], now()->addMinutes(15));
        $request->session()->put(self::SESSION_KEY, $token);
        $request->session()->put('oidc.state', $state);
        $request->session()->put('oidc.pkce', $pkce);

        return redirect()->away($url);
    }

    /** @param array<string, mixed> $configuration */
    private function integration(array $configuration, string $secret): IntegrationConfig
    {
        $integration = new IntegrationConfig(['provider' => 'oidc', 'name' => 'OpenID Connect (test)', 'mode' => 'active', 'enabled' => true, 'configuration' => $configuration]);
        $integration->credentials = ['client_secret' => $secret];

        return $integration;
    }

    /** @return list<string> */
    public static function words(string $value): array
    {
        return array_values(array_filter(preg_split('/[\s,]+/', $value) ?: []));
    }
}
