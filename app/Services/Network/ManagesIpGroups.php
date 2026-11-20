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
}
