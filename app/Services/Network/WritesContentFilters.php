<?php

namespace App\Services\Network;

/**
 * Implemented by providers that can replace a content filter's allow and
 * block lists. Only the lists change; categories, schedules and the networks
 * the filter applies to are sent back exactly as read.
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
     * Save the filter back unchanged to prove the key may write it.
     *
     * @return array{ok: bool, message: string}
     */
    public function verifyContentFilterWrite(string $filterId): array;
}
