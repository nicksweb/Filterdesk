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

    /** @return array{sub: string, email: string, name: string} */
    public function claims(AccessTokenInterface $token): array
    {
        $owner = $this->provider()->getResourceOwner($token)->toArray();
        $email = strtolower((string) ($owner['email'] ?? $owner['preferred_username'] ?? ''));
        $subject = (string) ($owner['sub'] ?? '');

        if ($email === '' || $subject === '') {
            throw new RuntimeException('The identity provider did not return a stable subject and email address.');
        }

        return ['sub' => $subject, 'email' => $email, 'name' => (string) ($owner['name'] ?? $email)];
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
