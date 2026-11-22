<?php

namespace App\Services\Network;

/**
 * Implemented by providers that can read the controller's content filters
 * and networks, so lists can be pulled instead of pasted and VLANs can be
 * mapped to filter policies.
 */
interface ReadsContentFilters
{
    /**
     * @return list<array{id: string, name: string, enabled: bool, allow: list<string>, block: list<string>, network_ids: list<string>, categories: list<string>}>
     *
     * @throws ProviderRequestFailed
     */
    public function contentFilters(): array;

    /**
     * Category names the controller can block, such as GAMBLING or SOCIAL_NETWORKS.
     *
     * @return list<string>
     *
     * @throws ProviderRequestFailed
     */
    public function availableCategories(): array;

    /**
     * Networks a content filter can be attached to.
     *
     * @return list<array{id: string, name: string, vlan: int|null, purpose: string|null, subnet: string|null}>
     *
     * @throws ProviderRequestFailed
     */
    public function networks(): array;
}
