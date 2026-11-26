<?php

namespace App\Services\Network;

/**
 * Providers that can keep a controller IP group (UniFi: traffic matching
 * list) in step, so a firewall rule that blocks the group blocks every
 * address in an IP blocklist.
 */
interface ManagesIpGroups
{
    /**
     * Every IP group with its addresses and subnets ("items").
     *
     * @return list<array{id: string, name: string, family: string, count: int, items?: list<string>}>
     *
     * @throws ProviderRequestFailed
     */
    public function ipGroups(): array;

    /**
     * Replace the group's addresses, then read back and confirm.
     *
     * @param  list<string>  $entries  Addresses or CIDR subnets.
     *
     * @throws ProviderRequestFailed
     */
    public function writeIpGroup(string $id, string $name, string $family, array $entries): void;

    /**
     * @param  list<string>  $entries
     *
     * @throws ProviderRequestFailed
     */
    public function createIpGroup(string $name, string $family, array $entries): string;

    /**
     * Firewall rules that use the group anywhere.
     *
     * @return list<array{name: string, action: string, enabled: bool}>
     *
     * @throws ProviderRequestFailed
     */
    public function firewallRulesUsing(string $id): array;

    /** @throws ProviderRequestFailed */
    public function deleteIpGroup(string $id): void;

    /**
     * Firewall zones, for choosing where blocking rules apply, with the
     * names of the networks in each.
     *
     * @return list<array{id: string, name: string, networks: list<string>, network_ids?: list<string>}>
     *
     * @throws ProviderRequestFailed
     */
    public function firewallZones(): array;

    /**
     * Create an enabled rule that blocks (or, with action ALLOW, allows)
     * traffic from one zone to another where the destination (or, for
     * incoming rules, the source) is in the group. An Allow rule can be
     * limited to some of the zone's networks (Integration API network IDs).
     *
     * @param  list<string>  $sourceNetworkIds
     *
     * @throws ProviderRequestFailed
     */
    public function createBlockRule(string $name, string $groupId, string $family, string $sourceZoneId, string $destinationZoneId, bool $groupIsSource = false, string $action = 'BLOCK', array $sourceNetworkIds = []): string;

    /**
     * Move rules (keeping their order) to just above another rule in the
     * same zone pair; to the top when that rule is not there.
     *
     * @param  list<string>  $ruleIds
     *
     * @throws ProviderRequestFailed
     */
    public function placeRulesBefore(string $sourceZoneId, string $destinationZoneId, array $ruleIds, string $beforeRuleId): void;

    /** @throws ProviderRequestFailed */
    public function deleteFirewallRule(string $id): void;

    /**
     * Every firewall rule, in evaluation order per zone pair (lower index
     * first). "match" describes the destination it applies to: any address,
     * an IP group, listed addresses, or something else ("unknown": a
     * domain, region or app that cannot be checked against an address),
     * with the ports when it names any.
     *
     * @return list<array{id: string, name: string, action: string, enabled: bool, index: int, source: string, destination: string, user: bool, match?: array{kind: string, group?: string, addresses?: list<string>, opposite?: bool, ports?: list<array{0: int, 1: int}>|null, label?: string}}>
     *
     * @throws ProviderRequestFailed
     */
    public function firewallRules(): array;

    /**
     * Move rules to the top of their zone pair, ahead of the other rules
     * people made (which keep their order), so nothing allows the
     * addresses before they are blocked.
     *
     * @param  list<string>  $ruleIds
     *
     * @throws ProviderRequestFailed
     */
    public function placeRulesFirst(string $sourceZoneId, string $destinationZoneId, array $ruleIds): void;
}
