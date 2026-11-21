<?php

namespace App\Services\Network;

/**
 * Providers that can pin a device's address: a fixed-IP reservation for a
 * MAC on a network, so the DHCP server never gives that address to anything
 * else. Used for probe containers.
 */
interface ManagesReservations
{
    /**
     * Fixed-IP reservations, keyed by lower-case MAC.
     *
     * @return array<string, array{ip: string, network_id: ?string, name: ?string}>
     *
     * @throws ProviderRequestFailed
     */
    public function reservations(): array;

    /**
     * Create or update the reservation for a MAC, then read it back.
     *
     * @throws ProviderRequestFailed
     */
    public function reserveAddress(string $mac, string $name, string $networkId, string $ip): void;
}
