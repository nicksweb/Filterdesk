<?php

namespace App\Services\Oidc;

use App\Models\IntegrationConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use League\OAuth2\Client\OptionProvider\HttpBasicAuthOptionProvider;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessTokenInterface;
use RuntimeException;
use Throwable;

/**
 * OpenID Connect sign-in for any standard provider (Entra ID, Google,
 * CloudWork ID…). It follows what the discovery document advertises: PKCE
 * when supported, client_secret_basic when the provider does not accept the
 * secret in the form body, and the ID token alone when there is no userinfo
 * endpoint. Extra scopes and the claims that carry groups or roles are
 * configurable, because providers name them differently.
 */
class OidcClient
{
    public const LAST_ERROR_KEY = 'oidc.last_error';

    public const LAST_CLAIMS_KEY = 'oidc.last_claims';

    /** Claims never shown back to administrators (personal or token mechanics). */
    private const PRIVATE_CLAIMS = ['sub', 'email', 'email_verified', 'name', 'given_name', 'family_name', 'middle_name', 'nickname', 'preferred_username', 'upn', 'unique_name',
        'picture', 'profile', 'website', 'phone_number', 'phone_number_verified', 'address', 'birthdate', 'gender', 'zoneinfo', 'locale', 'oid', 'sid', 'nonce', 'at_hash', 'c_hash',
        'iat', 'exp', 'nbf', 'iss', 'aud', 'azp', 'auth_time', 'acr', 'amr', 'jti', 'uti', 'rh', 'tid', 'ver', 'ipaddr'];

    /** A configuration not saved yet (the installer's Test sign-in), used instead of the stored one. */
    private ?IntegrationConfig $override = null;

    public function __construct(private readonly OidcDiscoveryService $discovery) {}

    /** The same client with a configuration that is not saved (for Test sign-in). */
    public function using(IntegrationConfig $configuration): static
    {
        $client = clone $this;
        $client->override = $configuration;

        return $client;
    }

    /** @return array{0: string, 1: string, 2: ?string} the URL, the state and the PKCE verifier (if used) */
    public function authorizationUrl(): array
    {
        $provider = $this->provider();
        $url = $provider->getAuthorizationUrl(['scope' => $this->scopes()]);

        return [$url, $provider->getState(), $provider->getPkceCode()];
    }

    public function exchange(string $code, ?string $pkceVerifier = null): AccessTokenInterface
    {
        $provider = $this->provider();
        if ($pkceVerifier) {
            $provider->setPkceCode($pkceVerifier);
        }

        return $provider->getAccessToken('authorization_code', ['code' => $code]);
    }

    /** @return list<string> */
    public function scopes(): array
    {
        $extra = (array) ($this->integration()->configuration['scopes'] ?? []);

        return array_values(array_unique(['openid', 'profile', 'email', ...array_filter(array_map('trim', $extra))]));
    }

    /**
     * Identity from userinfo (when the provider has it) and the ID token.
     *
     * The ID token is read without signature verification. That is permitted
     * here (OIDC Core 3.1.3.7) because it came straight from the token
     * endpoint over a TLS back-channel, not from the browser.
     *
     * @return array{sub: string, email: string, name: string, groups: list<string>, visible: array<string, string>, group_claims: list<string>}
     */
    public function claims(AccessTokenInterface $token): array
    {
        $idToken = $this->idTokenClaims((string) ($token->getValues()['id_token'] ?? ''));
        $owner = [];
        if (filled($this->endpoints()['userinfo_endpoint'] ?? null)) {
            $owner = $this->provider()->getResourceOwner($token)->toArray();
        }
        $claims = $owner + $idToken;

        $subject = (string) ($owner['sub'] ?? $idToken['sub'] ?? '');
        $email = strtolower((string) ($claims['email'] ?? $claims['preferred_username'] ?? $claims['upn'] ?? $claims['unique_name'] ?? ''));
        if ($email === '' || $subject === '') {
            $seen = implode(', ', array_keys($claims)) ?: 'none';
            throw new RuntimeException("The identity provider did not return a stable subject and email address (claims received: {$seen}). Make sure the app is allowed the email scope.");
        }
        if (isset($idToken['sub'], $owner['sub']) && ! hash_equals((string) $idToken['sub'], (string) $owner['sub'])) {
            throw new RuntimeException('The ID token subject does not match the userinfo subject.');
        }

        // Configured claim names, or (left blank) every claim whose name ends in groups, role or roles,
        // which covers Entra ("groups", "roles") and namespaced ones such as CloudWork's
        // "https://cloudwork.id/claims/groups" and ".../role".
        $claimNames = array_values(array_filter((array) ($this->integration()->configuration['group_claims'] ?? [])));
        // ["groups", "roles"] was the stored default before automatic detection; automatic covers both.
        if ($claimNames === [] || $claimNames === ['groups', 'roles']) {
            $claimNames = array_values(array_filter(array_keys($claims), fn ($name) => (bool) preg_match('#(^|[/:._-])(groups?|roles?)$#i', (string) $name)));
        }
        // Groups may come as a list, a single string, a ";"- or newline-separated string, or objects with a name.
        $groups = collect($claimNames)->map(fn ($name) => trim((string) $name))->filter()
            ->flatMap(fn (string $name) => [$owner[$name] ?? null, $idToken[$name] ?? null])
            ->flatMap(fn ($value) => is_array($value) ? $value : [$value])
            ->map(fn ($value) => is_array($value) ? ($value['name'] ?? $value['displayName'] ?? $value['email'] ?? $value['id'] ?? null) : $value)
            ->flatMap(fn ($value) => is_string($value) ? preg_split('/\s*[;\n]\s*/', $value) : (is_int($value) ? [(string) $value] : []))
            ->map(fn ($value) => trim((string) $value))->filter()->unique(fn (string $value) => strtolower($value))->values()->all();

        // What the provider sends, for writing role rules: every claim name, and the values of non-personal ones (role, type, group…).
        $visible = collect($claims)->map(fn ($value, $key) => in_array(strtolower((string) $key), self::PRIVATE_CLAIMS, true)
            ? '(not shown)' : str(is_scalar($value) ? (string) $value : (string) json_encode($value))->limit(120)->toString())->all();
        Cache::put(self::LAST_CLAIMS_KEY, ['at' => now()->toIso8601String(), 'claims' => $visible], now()->addDays(7));

        $name = (string) ($claims['name'] ?? trim(($claims['given_name'] ?? '').' '.($claims['family_name'] ?? '')) ?: $email);

        return ['sub' => $subject, 'email' => $email, 'name' => $name ?: $email, 'groups' => $groups, 'visible' => $visible,
            'group_claims' => array_values(array_filter($claimNames, fn ($claim) => array_key_exists($claim, $claims)))];
    }

    /** Remember why the last sign-in failed, for Settings → Authentication. */
    public static function recordFailure(Throwable $exception): void
    {
        $message = $exception->getMessage();
        if (method_exists($exception, 'getResponseBody')) {
            $body = $exception->getResponseBody();
            $message .= ' '.str(is_string($body) ? $body : json_encode($body))->limit(300);
        }
        Cache::put(self::LAST_ERROR_KEY, ['at' => now()->toIso8601String(), 'message' => str($message)->limit(600)->toString()], now()->addDays(7));
    }

    /** @return array<string, mixed> */
    private function idTokenClaims(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return [];
        }

        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);

        return is_array($payload) ? $payload : [];
    }

    public function integration(): IntegrationConfig
    {
        if ($this->override) {
            return $this->override;
        }

        return IntegrationConfig::query()->where('provider', 'oidc')->where('enabled', true)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function endpoints(): array
    {
        return $this->discovery->discover((string) ($this->integration()->configuration['issuer'] ?? ''));
    }

    private function provider(): GenericProvider
    {
        $integration = $this->integration();
        $config = $integration->configuration ?? [];
        $credentials = $integration->credentials ?? [];
        $endpoints = $this->endpoints();

        $methods = (array) ($endpoints['token_endpoint_auth_methods_supported'] ?? ['client_secret_basic']);
        $basic = match ($config['token_auth'] ?? 'auto') {
            'basic' => true,
            'post' => false,
            // The spec's default is client_secret_basic; send the secret in the body only where the provider takes it.
            default => ! in_array('client_secret_post', $methods, true),
        };
        $options = [
            'clientId' => (string) $config['client_id'],
            'clientSecret' => (string) ($credentials['client_secret'] ?? ''),
            'redirectUri' => route('auth.oidc.callback'),
            'urlAuthorize' => $endpoints['authorization_endpoint'],
            'urlAccessToken' => $endpoints['token_endpoint'],
            'urlResourceOwnerDetails' => (string) ($endpoints['userinfo_endpoint'] ?? ''),
            'scopeSeparator' => ' ',
        ];
        if (in_array('S256', (array) ($endpoints['code_challenge_methods_supported'] ?? []), true)) {
            $options['pkceMethod'] = GenericProvider::PKCE_METHOD_S256;
        }

        // Laravel's HTTP client (with timeouts) for the token and userinfo calls, instead of an unlimited default.
        $collaborators = ['httpClient' => Http::connectTimeout(5)->timeout(15)->buildClient()];
        if ($basic) {
            $collaborators['optionProvider'] = new HttpBasicAuthOptionProvider;
        }

        return new GenericProvider($options, $collaborators);
    }
}
