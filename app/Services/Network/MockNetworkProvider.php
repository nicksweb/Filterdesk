<?php

namespace App\Services\Network;

use App\Models\WebsiteRequest;

class MockNetworkProvider implements NetworkProvider
{
    public function capabilities(): array
    {
        return ['site_discovery' => true, 'network_discovery' => true, 'firewall_policies' => true, 'domain_allowlist' => true, 'scheduled_domain_allowlist' => true];
    }

    public function test(): array
    {
        return ['ok' => true, 'message' => 'Simulation provider is ready.'];
    }

    public function apply(WebsiteRequest $request): array
    {
        return ['resource_id' => 'simulated-'.$request->reference, 'domains' => $request->domains, 'simulated' => true];
    }

    public function revoke(WebsiteRequest $request): void {}
}
