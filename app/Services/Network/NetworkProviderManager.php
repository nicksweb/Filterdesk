<?php

namespace App\Services\Network;

use App\Models\IntegrationConfig;
use RuntimeException;

class NetworkProviderManager
{
    public function current(): NetworkProvider
    {
        $integration = IntegrationConfig::query()->where('enabled', true)->whereIn('provider', ['unifi', 'mock'])->first();

        if (! $integration) {
            throw new RuntimeException('No network integration is enabled.');
        }

        return match ($integration->provider) {
            'unifi' => new UniFiNetworkProvider($integration),
            'mock' => new MockNetworkProvider,
        };
    }

    public function integration(): ?IntegrationConfig
    {
        return IntegrationConfig::query()->where('enabled', true)->whereIn('provider', ['unifi', 'mock'])->first();
    }
}
