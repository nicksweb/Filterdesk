<?php

namespace App\Services\Network;

use App\Models\IntegrationConfig;
use RuntimeException;
use Throwable;

class NetworkProviderManager
{
    /** Provider keys this build can construct. Add new vendors here. */
    public const PROVIDERS = ['unifi', 'mock'];

    public function current(): NetworkProvider
    {
        $integration = $this->integration();

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
        return IntegrationConfig::query()->where('enabled', true)->whereIn('provider', self::PROVIDERS)->first();
    }

    /** Rules from the active provider, or conservative defaults when none is configured. */
    public function domainRules(): DomainRules
    {
        try {
            return $this->current()->domainRules();
        } catch (Throwable) {
            return new DomainRules;
        }
    }
}
