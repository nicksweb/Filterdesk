<?php

namespace App\Services\Network;

use App\Models\WebsiteRequest;

class MockNetworkProvider implements NetworkProvider
{
    public function capabilities(): array
    {
        return ['site_discovery' => true, 'network_discovery' => true, 'firewall_policies' => true, 'domain_allowlist' => true, 'scheduled_domain_allowlist' => true];
    }

    public function domainRules(): DomainRules
    {
        return new DomainRules(notes: ['Simulation provider: entries are listed explicitly, as with UniFi DNS filtering.']);
    }

    public function test(): array
    {
        return ['ok' => true, 'message' => 'Simulation provider is ready.'];
    }

    public function discover(?string $siteId = null): array
    {
        return [
            'sites' => [['id' => 'default', 'name' => 'Simulated site']],
            'networks' => [
                ['id' => 'sim-student', 'name' => 'Student Access', 'vlan' => 20],
                ['id' => 'sim-staff', 'name' => 'Staff Access', 'vlan' => 10],
            ],
        ];
    }

    public function apply(WebsiteRequest $request): array
    {
        return ['resource_id' => 'simulated-'.$request->reference, 'domains' => $request->effectiveDomains(), 'simulated' => true];
    }

    public function revoke(WebsiteRequest $request): void {}
}
