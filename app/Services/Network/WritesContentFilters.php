<?php

namespace App\Services\Network;

/**
 * Implemented by providers that can replace a content filter's allow and
 * block lists. Only what a method names changes; everything else is sent
 * back exactly as read.
 */
interface WritesContentFilters
{
    /**
     * Replace both lists, then read the filter back and confirm it matches.
     *
     * @param  list<string>  $allow
     * @param  list<string>  $block
     *
     * @throws ProviderRequestFailed
     */
    public function writeContentFilterLists(string $filterId, array $allow, array $block): void;

    /**
     * Replace the blocked content categories, then read back and confirm.
     *
     * @param  list<string>  $categories
     *
     * @throws ProviderRequestFailed
     */
    public function writeContentFilterCategories(string $filterId, array $categories): void;

    /**
     * Create an enabled filter with empty lists on the given networks, then
     * read it back. Returns the filter in the same shape as contentFilters().
     *
     * @param  list<string>  $networkIds
     * @param  list<string>  $categories  at least one; UniFi rejects a filter without categories
     * @return array{id: string, name: string, enabled: bool, allow: list<string>, block: list<string>, network_ids: list<string>, categories: list<string>, safe_search: list<string>}
     *
     * @throws ProviderRequestFailed
     */
    public function createContentFilter(string $name, array $networkIds, array $categories): array;

    /**
     * Add networks to a filter (keeping the ones it has), then read it back
     * and confirm they are there. Lists and categories are sent back as read.
     *
     * @param  list<string>  $networkIds
     *
     * @throws ProviderRequestFailed
     */
    public function attachNetworksToContentFilter(string $filterId, array $networkIds): void;

    /**
     * Take networks off a filter (keeping the rest), then read it back and
     * confirm they are gone.
     *
     * @param  list<string>  $networkIds
     *
     * @throws ProviderRequestFailed
     */
    public function detachNetworksFromContentFilter(string $filterId, array $networkIds): void;

    /**
     * Save the filter back unchanged to prove the key may write it.
     *
     * @return array{ok: bool, message: string}
     */
    public function verifyContentFilterWrite(string $filterId): array;
}
