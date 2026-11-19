<?php

namespace App\Services\Network;

use App\Models\WebsiteRequest;
use Illuminate\Support\Facades\Cache;

class MockNetworkProvider implements NetworkProvider, ReadsContentFilters, WritesContentFilters
{
    public function capabilities(): array
    {
        return ['site_discovery' => true, 'network_discovery' => true, 'firewall_policies' => true, 'content_filter_read' => true, 'content_filter_write' => true, 'domain_allowlist' => true, 'scheduled_domain_allowlist' => true];
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

    private const STATE_KEY = 'mock.content_filters';

    public function contentFilters(): array
    {
        return Cache::get(self::STATE_KEY, fn () => [
            ['id' => 'sim-filter-students', 'name' => 'Students', 'enabled' => true, 'allow' => ['example.edu'], 'block' => [], 'network_ids' => ['sim-student'], 'categories' => ['ADULT'], 'safe_search' => []],
            ['id' => 'sim-filter-staff', 'name' => 'Staff', 'enabled' => true, 'allow' => [], 'block' => [], 'network_ids' => ['sim-staff'], 'categories' => [], 'safe_search' => []],
        ]);
    }

    public function writeContentFilterLists(string $filterId, array $allow, array $block): void
    {
        $filters = $this->contentFilters();
        $index = collect($filters)->search(fn (array $filter) => $filter['id'] === $filterId);
        if ($index === false) {
            throw new ProviderRequestFailed('That content filter no longer exists in the simulation.');
        }
        $filters[$index]['allow'] = array_values($allow);
        $filters[$index]['block'] = array_values($block);
        Cache::forever(self::STATE_KEY, $filters);
    }

    public function availableCategories(): array
    {
        return ['ADULT', 'GAMBLING', 'GAMES', 'SOCIAL_NETWORKS', 'STREAMING_MEDIA'];
    }

    public function writeContentFilterCategories(string $filterId, array $categories): void
    {
        $filters = $this->contentFilters();
        $index = collect($filters)->search(fn (array $filter) => $filter['id'] === $filterId);
        if ($index === false) {
            throw new ProviderRequestFailed('That content filter no longer exists in the simulation.');
        }
        $filters[$index]['categories'] = array_values($categories);
        Cache::forever(self::STATE_KEY, $filters);
    }

    public function verifyContentFilterWrite(string $filterId): array
    {
        return ['ok' => true, 'message' => 'Simulation provider accepts content filter changes.'];
    }

    public function networks(): array
    {
        return [
            ['id' => 'sim-staff', 'name' => 'Staff Access', 'vlan' => 10, 'purpose' => 'corporate', 'subnet' => '10.0.10.1/24'],
            ['id' => 'sim-student', 'name' => 'Student Access', 'vlan' => 20, 'purpose' => 'corporate', 'subnet' => '10.0.20.1/24'],
            ['id' => 'sim-guest', 'name' => 'Guest Wi-Fi', 'vlan' => 80, 'purpose' => 'guest', 'subnet' => '10.0.80.1/24'],
        ];
    }

    public function apply(WebsiteRequest $request): array
    {
        return ['resource_id' => 'simulated-'.$request->reference, 'domains' => $request->effectiveDomains(), 'simulated' => true];
    }

    public function revoke(WebsiteRequest $request): void {}
}
