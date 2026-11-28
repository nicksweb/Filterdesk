<?php

namespace App\Services\Network;

/**
 * UniFi refused an IP list because of its stored size, not its number of
 * entries: "Message size 206251 exceeds the maximum allowed size of 126000
 * bytes". How many entries fit depends on the addresses, so the pusher
 * splits the list further and tries again.
 */
class IpListTooLarge extends ProviderRequestFailed
{
    public function __construct(string $message, public readonly int $size, public readonly int $limit, public readonly int $entries = 0)
    {
        parent::__construct($message);
    }

    /** About how many entries of this kind fit in one list, with a 10% margin. */
    public function entriesThatFit(): int
    {
        return $this->entries > 0 && $this->size > 0 ? max(500, (int) floor($this->entries * $this->limit / $this->size * 0.9)) : 0;
    }
}
