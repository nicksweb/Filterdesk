<?php

namespace App\Services\Network;

use App\Models\WebsiteRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class MockNetworkProvider implements ManagesIpGroups, ManagesReservations, NetworkProvider, ReadsContentFilters, WritesContentFilters
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

    public function attachNetworksToContentFilter(string $filterId, array $networkIds): void
    {
        $filters = $this->contentFilters();
        $index = collect($filters)->search(fn (array $filter) => $filter['id'] === $filterId);
        if ($index === false) {
            throw new ProviderRequestFailed('That content filter no longer exists in the simulation.');
        }
        $filters[$index]['network_ids'] = array_values(array_unique([...$filters[$index]['network_ids'], ...$networkIds]));
        Cache::forever(self::STATE_KEY, $filters);
    }

    public function detachNetworksFromContentFilter(string $filterId, array $networkIds): void
    {
        $filters = $this->contentFilters();
        $index = collect($filters)->search(fn (array $filter) => $filter['id'] === $filterId);
        if ($index === false) {
            throw new ProviderRequestFailed('That content filter no longer exists in the simulation.');
        }
        $filters[$index]['network_ids'] = array_values(array_diff($filters[$index]['network_ids'], $networkIds));
        Cache::forever(self::STATE_KEY, $filters);
    }

    public function createContentFilter(string $name, array $networkIds, array $categories): array
    {
        $filter = ['id' => 'sim-filter-'.Str::slug($name).'-'.Str::lower(Str::random(4)), 'name' => $name, 'enabled' => true, 'allow' => [], 'block' => [],
            'network_ids' => array_values($networkIds), 'categories' => array_values($categories), 'safe_search' => []];
        Cache::forever(self::STATE_KEY, [...$this->contentFilters(), $filter]);

        return $filter;
    }

    public function reservations(): array
    {
        return Cache::get('mock.reservations', []);
    }

    public function reserveAddress(string $mac, string $name, string $networkId, string $ip): void
    {
        Cache::forever('mock.reservations', [strtolower($mac) => ['ip' => $ip, 'network_id' => $networkId, 'name' => $name]] + $this->reservations());
    }

    public function availableCategories(): array
    {
        return ['ADULT', 'GAMBLING', 'GAMES', 'RELIGION', 'SOCIAL_NETWORKS', 'STREAMING_MEDIA'];
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

    private const GROUPS_KEY = 'mock.ip_groups';

    /** @return array<string, array{id: string, name: string, family: string, items: list<string>}> */
    public function rawIpGroups(): array
    {
        return Cache::get(self::GROUPS_KEY, fn () => ['sim-group-1' => ['id' => 'sim-group-1', 'name' => 'Blocked IPs', 'family' => 'ipv4', 'items' => []]]);
    }

    public function ipGroups(): array
    {
        return array_values(array_map(fn (array $group) => ['id' => $group['id'], 'name' => $group['name'], 'family' => $group['family'], 'count' => count($group['items']), 'items' => $group['items']], $this->rawIpGroups()));
    }

    public function writeIpGroup(string $id, string $name, string $family, array $entries): void
    {
        $groups = $this->rawIpGroups();
        if (! isset($groups[$id])) {
            throw new ProviderRequestFailed('That IP group no longer exists in the simulation.');
        }
        $groups[$id]['items'] = array_values($entries);
        Cache::forever(self::GROUPS_KEY, $groups);
    }

    public function createIpGroup(string $name, string $family, array $entries): string
    {
        $groups = $this->rawIpGroups();
        $id = 'sim-group-'.(count($groups) + 1);
        $groups[$id] = ['id' => $id, 'name' => $name, 'family' => $family, 'items' => array_values($entries)];
        Cache::forever(self::GROUPS_KEY, $groups);

        return $id;
    }

    public function firewallRulesUsing(string $id): array
    {
        $rules = collect(Cache::get('mock.firewall_rules', []))->filter(fn (array $rule) => ($rule['group'] ?? null) === $id)
            ->map(fn (array $rule) => ['name' => $rule['name'], 'action' => $rule['action'] ?? 'BLOCK', 'enabled' => $rule['enabled'] ?? true])->values()->all();

        return $id === 'sim-group-1' ? [['name' => 'Block listed IPs', 'action' => 'BLOCK', 'enabled' => true], ...$rules] : $rules;
    }

    public function deleteIpGroup(string $id): void
    {
        $groups = $this->rawIpGroups();
        unset($groups[$id]);
        Cache::forever(self::GROUPS_KEY, $groups);
    }

    public function firewallZones(): array
    {
        return [
            ['id' => 'zone-internal', 'name' => 'Internal', 'networks' => ['Staff Access', 'Student Access'], 'network_ids' => ['int-staff', 'int-student'],
                'members' => [['id' => 'int-staff', 'name' => 'Staff Access', 'vlan' => 10], ['id' => 'int-student', 'name' => 'Student Access', 'vlan' => 20]]], ['id' => 'zone-external', 'name' => 'External', 'networks' => [], 'network_ids' => []],
            ['id' => 'zone-gateway', 'name' => 'Gateway', 'networks' => [], 'network_ids' => []], ['id' => 'zone-hotspot', 'name' => 'Hotspot', 'networks' => ['Guest Wi-Fi'], 'network_ids' => ['int-guest'], 'members' => [['id' => 'int-guest', 'name' => 'Guest Wi-Fi', 'vlan' => 80]]], ['id' => 'zone-vpn', 'name' => 'Vpn', 'networks' => [], 'network_ids' => []],
        ];
    }

    public function createBlockRule(string $name, string $groupId, string $family, string $sourceZoneId, string $destinationZoneId, bool $groupIsSource = false, string $action = 'BLOCK', array $sourceNetworkIds = []): string
    {
        $rules = Cache::get('mock.firewall_rules', []);
        $id = 'sim-rule-'.(count($rules) + 1).'-'.substr(md5($name.$groupId.$sourceZoneId), 0, 6);
        $rules[$id] = ['id' => $id, 'name' => $name, 'group' => $groupId, 'source' => $sourceZoneId, 'destination' => $destinationZoneId, 'incoming' => $groupIsSource, 'action' => $action,
            'source_match' => $sourceNetworkIds ? ['kind' => 'networks', 'networks' => $sourceNetworkIds, 'opposite' => false] : ['kind' => 'any'],
            'states' => $action === 'ALLOW' ? ['NEW', 'RELATED', 'ESTABLISHED'] : null];
        Cache::forever('mock.firewall_rules', $rules);

        return $id;
    }

    public function deleteFirewallRule(string $id): void
    {
        $rules = Cache::get('mock.firewall_rules', []);
        unset($rules[$id]);
        Cache::forever('mock.firewall_rules', $rules);
    }

    public function firewallRules(): array
    {
        $order = Cache::get('mock.firewall_first', []);

        return collect(Cache::get('mock.firewall_rules', []))->values()->map(fn (array $rule, int $index) => [
            'id' => $rule['id'], 'name' => $rule['name'], 'action' => $rule['action'] ?? 'BLOCK', 'enabled' => $rule['enabled'] ?? true,
            'index' => in_array($rule['id'], $order, true) ? array_search($rule['id'], $order, true) : 10000 + $index,
            'source' => $rule['source'], 'destination' => $rule['destination'], 'user' => $rule['user'] ?? true,
            'match' => $rule['match'] ?? (isset($rule['group']) ? ['kind' => 'group', 'group' => $rule['group'], 'opposite' => false, 'ports' => null] : ['kind' => 'any', 'ports' => null]),
            'source_match' => $rule['source_match'] ?? ['kind' => 'any'], 'protocol' => $rule['protocol'] ?? 'any', 'ip_version' => $rule['ip_version'] ?? 'IPV4_AND_IPV6', 'states' => $rule['states'] ?? null,
        ])->all();
    }

    public function placeRulesBefore(string $sourceZoneId, string $destinationZoneId, array $ruleIds, string $beforeRuleId): void
    {
        $order = array_values(array_diff(Cache::get('mock.firewall_first', []), $ruleIds));
        $at = array_search($beforeRuleId, $order, true);
        Cache::forever('mock.firewall_first', $at === false ? [...$ruleIds, $beforeRuleId, ...$order] : [...array_slice($order, 0, $at), ...$ruleIds, ...array_slice($order, $at)]);
    }

    public function placeRulesFirst(string $sourceZoneId, string $destinationZoneId, array $ruleIds): void
    {
        Cache::forever('mock.firewall_first', array_values(array_unique([...$ruleIds, ...Cache::get('mock.firewall_first', [])])));
    }

    public function verifyContentFilterWrite(string $filterId): array
    {
        return ['ok' => true, 'message' => 'Simulation provider accepts content filter changes.'];
    }

    public function networks(): array
    {
        return Cache::get('mock.networks', fn () => [
            ['id' => 'sim-staff', 'name' => 'Staff Access', 'vlan' => 10, 'purpose' => 'corporate', 'subnet' => '10.0.10.1/24'],
            ['id' => 'sim-student', 'name' => 'Student Access', 'vlan' => 20, 'purpose' => 'corporate', 'subnet' => '10.0.20.1/24'],
            ['id' => 'sim-guest', 'name' => 'Guest Wi-Fi', 'vlan' => 80, 'purpose' => 'guest', 'subnet' => '10.0.80.1/24', 'dhcp_start' => '10.0.80.6', 'dhcp_stop' => '10.0.80.254'],
        ]);
    }

    public function apply(WebsiteRequest $request): array
    {
        return ['resource_id' => 'simulated-'.$request->reference, 'domains' => $request->effectiveDomains(), 'simulated' => true];
    }

    public function revoke(WebsiteRequest $request): void {}
}
