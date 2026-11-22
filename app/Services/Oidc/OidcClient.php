<?php

namespace App\Services\Oidc;

use App\Models\IntegrationConfig;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessTokenInterface;
use RuntimeException;

class OidcClient
{
    public function __construct(private readonly OidcDiscoveryService $discovery) {}

    /** @return array{0: string, 1: string} */
    public function authorizationUrl(): array
    {
        $provider = $this->provider();
        $url = $provider->getAuthorizationUrl(['scope' => ['openid', 'profile', 'email']]);

        return [$url, $provider->getState()];
    }

    public function exchange(string $code): AccessTokenInterface
    {
        return $this->provider()->getAccessToken('authorization_code', ['code' => $code]);
    }

    /**
     * Identity from userinfo, plus group/role claims from the ID token.
     *
     * The ID token is read without signature verification. That is permitted
     * here (OIDC Core 3.1.3.7) because it came straight from the token
     * endpoint over a TLS back-channel, not from the browser.
     *
     * @return array{sub: string, email: string, name: string, groups: list<string>}
     */
    public function claims(AccessTokenInterface $token): array
    {
        $owner = $this->provider()->getResourceOwner($token)->toArray();
        $idToken = $this->idTokenClaims((string) ($token->getValues()['id_token'] ?? ''));
        $email = strtolower((string) ($owner['email'] ?? $owner['preferred_username'] ?? $idToken['email'] ?? $idToken['preferred_username'] ?? ''));
        $subject = (string) ($owner['sub'] ?? '');

        if ($email === '' || $subject === '') {
            throw new RuntimeException('The identity provider did not return a stable subject and email address.');
        }

        if (isset($idToken['sub']) && ! hash_equals((string) $idToken['sub'], $subject)) {
            throw new RuntimeException('The ID token subject does not match the userinfo subject.');
        }

        $groups = collect([$owner['groups'] ?? [], $owner['roles'] ?? [], $idToken['groups'] ?? [], $idToken['roles'] ?? []])
            ->flatten()->filter(fn ($value) => is_string($value) && $value !== '')->map(fn (string $value) => strtolower($value))->unique()->values()->all();

        return ['sub' => $subject, 'email' => $email, 'name' => (string) ($owner['name'] ?? $email), 'groups' => $groups];
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
        return IntegrationConfig::query()->where('provider', 'oidc')->where('enabled', true)->firstOrFail();
    }

    private function provider(): GenericProvider
    {
        $integration = $this->integration();
        $config = $integration->configuration ?? [];
        $credentials = $integration->credentials ?? [];
        $endpoints = $this->discovery->discover((string) $config['issuer']);

        return new GenericProvider([
            'clientId' => (string) $config['client_id'],
            'clientSecret' => (string) ($credentials['client_secret'] ?? ''),
            'redirectUri' => route('auth.oidc.callback'),
            'urlAuthorize' => $endpoints['authorization_endpoint'],
            'urlAccessToken' => $endpoints['token_endpoint'],
            'urlResourceOwnerDetails' => $endpoints['userinfo_endpoint'],
            'scopeSeparator' => ' ',
        ]);
    }
}
