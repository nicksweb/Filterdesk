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
     * @return list<array{id: string, name: string, family: string, count: int}>
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
     * @return list<array{id: string, name: string, networks: list<string>}>
     *
     * @throws ProviderRequestFailed
     */
    public function firewallZones(): array;

    /**
     * Create an enabled rule that blocks traffic from one zone to another
     * where the destination (or, for incoming rules, the source) is in the group.
     *
     * @throws ProviderRequestFailed
     */
    public function createBlockRule(string $name, string $groupId, string $family, string $sourceZoneId, string $destinationZoneId, bool $groupIsSource = false): string;

    /** @throws ProviderRequestFailed */
    public function deleteFirewallRule(string $id): void;

    /**
     * Every firewall rule, in evaluation order per zone pair (lower index first).
     *
     * @return list<array{id: string, name: string, action: string, enabled: bool, index: int, source: string, destination: string, user: bool}>
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
