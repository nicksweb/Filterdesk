<?php

namespace App\Services\Policy;

use App\Models\FirewallList;

/**
 * Makes a large IP feed fit a UniFi firewall list (20,000 addresses per IP
 * list, and every extra list costs a rule per zone):
 *
 * 1. private, reserved and documentation addresses are dropped (they never
 *    cross the External zone), and so is anything a firewall allow list lets
 *    through, so the two lists don't contradict each other;
 * 2. optionally, a /24 with at least N listed addresses becomes the whole /24
 *    (abuse tends to come from a few busy subnets);
 * 3. overlapping and neighbouring addresses are merged into the fewest
 *    subnets, losslessly;
 * 4. if the result is still over the limit, the widest ranges are kept first
 *    (they cover the most listed addresses), then single addresses spread
 *    evenly across the address space rather than only the lowest ones.
 *
 * IPv6 entries are only de-duplicated and filtered.
 */
class IpFeedProcessor
{
    /**
     * @param  list<string>  $values  addresses or subnets from the feed, normalised
     * @param  array{collapse?: int|null}  $options
     * @return array{entries: list<string>, stats: array{read: int, dropped_reserved: int, dropped_allowed: int, collapsed: int, merged_to: int, kept: int, cut: int}}
     */
    public function process(array $values, string $family, int $limit, array $options = []): array
    {
        $stats = ['read' => count($values), 'dropped_reserved' => 0, 'dropped_allowed' => 0, 'collapsed' => 0, 'merged_to' => 0, 'kept' => 0, 'cut' => 0];
        $allowed = $this->allowed($family);

        $kept = [];
        foreach ($values as $value) {
            $address = explode('/', $value)[0];
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false || $this->documentation($address)) {
                $stats['dropped_reserved']++;

                continue;
            }
            if ($allowed !== [] && collect($allowed)->contains(fn (string $entry) => FirewallEvaluator::contains($entry, $address))) {
                $stats['dropped_allowed']++;

                continue;
            }
            $kept[] = $value;
        }

        if ($family === 'ipv6') {
            $entries = array_values(array_unique($kept));
            sort($entries);
            $stats['merged_to'] = count($entries);
            $stats['kept'] = min(count($entries), $limit);
            $stats['cut'] = max(0, count($entries) - $limit);

            return ['entries' => array_slice($entries, 0, $limit), 'stats' => $stats];
        }

        // As integer ranges [start, end].
        $ranges = array_map(fn (string $value) => $this->range($value), $kept);
        $threshold = (int) ($options['collapse'] ?? 0);
        if ($threshold >= 2) {
            $perBlock = [];
            foreach ($ranges as [$start, $end]) {
                if ($start === $end) {
                    $perBlock[$start >> 8] = ($perBlock[$start >> 8] ?? 0) + 1;
                }
            }
            $dense = array_filter($perBlock, fn (int $count) => $count >= $threshold);
            $stats['collapsed'] = count($dense);
            foreach ($dense as $block => $count) {
                $ranges[] = [$block << 8, ($block << 8) | 0xFF];
            }
        }

        $merged = $this->merge($ranges);
        $cidrs = [];
        foreach ($merged as [$start, $end]) {
            foreach ($this->toCidrs($start, $end) as $cidr) {
                $cidrs[] = $cidr;
            }
        }
        $stats['merged_to'] = count($cidrs);

        if (count($cidrs) > $limit) {
            // Widest first; single addresses in an even spread (by a hash, so the choice is stable between refreshes).
            usort($cidrs, fn (array $a, array $b) => [$a[1], crc32((string) $a[0])] <=> [$b[1], crc32((string) $b[0])]);
            $stats['cut'] = count($cidrs) - $limit;
            $cidrs = array_slice($cidrs, 0, $limit);
            usort($cidrs, fn (array $a, array $b) => $a[0] <=> $b[0]);
        }
        $stats['kept'] = count($cidrs);

        return ['entries' => array_map(fn (array $cidr) => $cidr[1] === 32 ? long2ip($cidr[0]) : long2ip($cidr[0]).'/'.$cidr[1], $cidrs), 'stats' => $stats];
    }

    /** One line about what processing did, for the feed's row. */
    public static function describe(array $stats): string
    {
        $parts = ['read '.number_format($stats['read'])];
        if ($stats['dropped_reserved']) {
            $parts[] = number_format($stats['dropped_reserved']).' private or reserved dropped';
        }
        if ($stats['dropped_allowed']) {
            $parts[] = number_format($stats['dropped_allowed']).' on a firewall allow list dropped';
        }
        if ($stats['collapsed']) {
            $parts[] = number_format($stats['collapsed']).' busy /24s taken whole';
        }
        $parts[] = 'merged into '.number_format($stats['merged_to']).' entries';
        if ($stats['cut']) {
            $parts[] = number_format($stats['cut']).' single addresses left out by the limit';
        }

        return ucfirst(implode(', ', $parts)).'.';
    }

    /** @return list<string> addresses and subnets firewall allow lists let through */
    private function allowed(string $family): array
    {
        return FirewallList::query()->where('kind', 'allow')->where('family', $family)->where('enabled', true)->get()
            ->flatMap(fn (FirewallList $list) => $list->desiredEntries()['entries'])->unique()->values()->all();
    }

    /** TEST-NET and benchmarking ranges PHP's reserved flag does not cover. */
    private function documentation(string $address): bool
    {
        if (str_contains($address, ':')) {
            return false;
        }
        $long = ip2long($address);
        foreach ([['192.0.2.0', 24], ['198.51.100.0', 24], ['203.0.113.0', 24], ['198.18.0.0', 15], ['100.64.0.0', 10]] as [$network, $bits]) {
            if (($long >> (32 - $bits)) === (ip2long($network) >> (32 - $bits))) {
                return true;
            }
        }

        return false;
    }

    /** @return array{0: int, 1: int} */
    private function range(string $value): array
    {
        [$address, $prefix] = array_pad(explode('/', $value, 2), 2, '32');
        $start = ip2long($address) & (-1 << (32 - (int) $prefix)) & 0xFFFFFFFF;

        return [$start, $start + (1 << (32 - (int) $prefix)) - 1];
    }

    /**
     * @param  list<array{0: int, 1: int}>  $ranges
     * @return list<array{0: int, 1: int}>
     */
    private function merge(array $ranges): array
    {
        usort($ranges, fn (array $a, array $b) => $a[0] <=> $b[0] ?: $b[1] <=> $a[1]);
        $merged = [];
        foreach ($ranges as $range) {
            $last = count($merged) - 1;
            if ($last >= 0 && $range[0] <= $merged[$last][1] + 1) {
                $merged[$last][1] = max($merged[$last][1], $range[1]);
            } else {
                $merged[] = $range;
            }
        }

        return $merged;
    }

    /**
     * The fewest CIDR blocks that cover exactly [start, end].
     *
     * @return list<array{0: int, 1: int}> [network, prefix]
     */
    private function toCidrs(int $start, int $end): array
    {
        $cidrs = [];
        while ($start <= $end) {
            $size = $start === 0 ? 32 : min(32, $this->trailingZeros($start));
            while ($size > 0 && $start + (1 << $size) - 1 > $end) {
                $size--;
            }
            $cidrs[] = [$start, 32 - $size];
            $start += 1 << $size;
        }

        return $cidrs;
    }

    private function trailingZeros(int $value): int
    {
        $count = 0;
        while ($count < 32 && ($value & (1 << $count)) === 0) {
            $count++;
        }

        return $count;
    }
}
