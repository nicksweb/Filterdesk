<?php

namespace App\Services\Network;

/**
 * How a provider's domain lists behave. UniFi content filtering is DNS based:
 * entries are bare hostnames, with no paths, ports, schemes or wildcards.
 */
final readonly class DomainRules
{
    /** @param list<string> $notes */
    public function __construct(
        public bool $entriesIncludeSubdomains = false,
        public bool $supportsWildcards = false,
        public bool $supportsPaths = false,
        public ?int $maxEntries = null,
        public array $notes = [],
    ) {}

    /** Whether an entry already covers the given hostname under these rules. */
    public function covers(string $entry, string $host): bool
    {
        if ($entry === $host) {
            return true;
        }

        return $this->entriesIncludeSubdomains && str_ends_with($host, '.'.$entry);
    }
}
