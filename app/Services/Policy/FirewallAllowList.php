<?php

namespace App\Services\Policy;

use App\Models\FirewallList;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Domains\DomainNormalizer;
use App\Services\Website\SiteAddresses;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * Entries in a firewall allow list: addresses, subnets and domains, each
 * with a note, the request it came from, an expiry and who added it.
 * Domains are resolved through the probes (the school's DNS) and kept up to
 * date hourly; an address a domain no longer resolves to is dropped after a
 * week. Allowing an address allows every site on it, so shared and CDN
 * addresses and wide subnets need a reason, and very wide ones are refused.
 */
class FirewallAllowList
{
    /** Addresses a domain stopped resolving to are kept this long, so a short DNS change does not break it. */
    public const GRACE_DAYS = 7;

    /** Never allow wider than this (IPv4 /16, IPv6 /48). Wider than /24 (IPv6 /64) needs a reason. */
    public const WIDEST_V4 = 16;

    public const WIDEST_V6 = 48;

    /**
     * Shared ranges: allowing one of these lets through every site that uses
     * the provider. Cloudflare and Fastly publish theirs; others can be added
     * in config/blocklists.php (shared_ranges).
     *
     * @var array<string, list<string>>
     */
    public const SHARED = [
        'Cloudflare' => ['173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
            '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22', '2400:cb00::/32', '2606:4700::/32',
            '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32'],
        'Fastly' => ['23.235.32.0/20', '43.249.72.0/22', '103.244.50.0/24', '103.245.222.0/23', '103.245.224.0/24', '104.156.80.0/20', '140.248.64.0/18', '140.248.128.0/17',
            '146.75.0.0/17', '151.101.0.0/16', '157.52.64.0/18', '167.82.0.0/17', '167.82.128.0/20', '167.82.160.0/20', '167.82.224.0/20', '172.111.64.0/18', '185.31.16.0/22',
            '199.27.72.0/21', '199.232.0.0/16', '2a04:4e40::/32', '2a04:4e42::/32'],
    ];

    public function __construct(
        private readonly SiteAddresses $addresses,
        private readonly DomainNormalizer $normalizer,
        private readonly AuditLogger $audit,
    ) {}

    /** The allow list new entries go to by default: the first enabled one. */
    public static function default(): ?FirewallList
    {
        return FirewallList::query()->where('kind', 'allow')->where('enabled', true)->orderBy('id')->first();
    }

    /**
     * Check what adding these values would do, without saving: what each
     * one is, the addresses a domain resolves to, and warnings.
     *
     * @param  list<string>  $values
     * @return list<array{value: string, type: ?string, addresses: list<string>, warnings: list<string>, error: ?string, needs_reason: bool}>
     */
    public function inspect(FirewallList $list, array $values): array
    {
        $result = [];
        foreach (array_values(array_unique(array_filter(array_map(fn ($value) => strtolower(trim((string) $value)), $values)))) as $value) {
            $result[] = $this->inspectOne($list, $value);
        }

        return $result;
    }

    /**
     * Add entries. Each needs to pass inspect(); ones that need a reason are
     * refused without one.
     *
     * @param  list<string>  $values
     * @return array{added: list<string>, refused: list<string>, warnings: list<string>}
     */
    public function add(FirewallList $list, array $values, ?User $actor, ?string $note = null, ?string $request = null, ?string $expiresAt = null, ?string $reason = null): array
    {
        $entries = $list->allow_entries ?? [];
        $added = [];
        $refused = [];
        $warnings = [];
        foreach ($this->inspect($list, $values) as $check) {
            if ($check['error']) {
                $refused[] = "{$check['value']}: {$check['error']}";

                continue;
            }
            if ($check['needs_reason'] && blank($reason)) {
                $refused[] = "{$check['value']}: ".implode(' ', $check['warnings']).' Give a reason to allow it anyway.';

                continue;
            }
            $warnings = [...$warnings, ...array_map(fn (string $warning) => "{$check['value']}: {$warning}", $check['warnings'])];
            $existing = collect($entries)->search(fn (array $entry) => $entry['value'] === $check['value']);
            $entry = [
                'id' => $existing !== false ? $entries[$existing]['id'] : (string) Str::uuid(),
                'value' => $check['value'], 'type' => $check['type'],
                'note' => trim(implode(' · ', array_filter([$note, $reason ? "Reason: {$reason}" : null]))) ?: null,
                'request' => $request, 'expires_at' => $expiresAt,
                'added_by' => $actor?->name, 'added_at' => now()->toIso8601String(),
                'addresses' => $check['type'] === 'domain' ? array_fill_keys($check['addresses'], now()->toIso8601String()) : null,
            ];
            if ($existing !== false) {
                // An entry added again keeps the longer of the two expiries (none is longest).
                $old = $entries[$existing]['expires_at'] ?? null;
                $entry['expires_at'] = $old === null || $expiresAt === null ? null : max($old, $expiresAt);
                $entry['request'] = $request ?? ($entries[$existing]['request'] ?? null);
                $entries[$existing] = $entry;
            } else {
                $entries[] = $entry;
            }
            $added[] = $check['value'].($check['type'] === 'domain' && $check['addresses'] ? ' ('.implode(', ', $check['addresses']).')' : '');
        }
        $list->forceFill(['allow_entries' => array_values($entries)])->save();
        if ($added) {
            $this->audit->log('firewall.allow_added', $list, ['added' => $added, 'note' => $note, 'request' => $request, 'expires_at' => $expiresAt, 'reason' => $reason, 'by' => $actor?->id]);
        }

        return ['added' => $added, 'refused' => $refused, 'warnings' => array_values(array_unique($warnings))];
    }

    /** @param list<string> $ids */
    public function remove(FirewallList $list, array $ids, ?User $actor): int
    {
        $before = $list->allow_entries ?? [];
        $kept = array_values(array_filter($before, fn (array $entry) => ! in_array($entry['id'], $ids, true)));
        $list->forceFill(['allow_entries' => $kept])->save();
        $removed = count($before) - count($kept);
        if ($removed) {
            $this->audit->log('firewall.allow_removed', $list, ['removed' => collect($before)->whereIn('id', $ids)->pluck('value')->all(), 'by' => $actor?->id]);
        }

        return $removed;
    }

    /**
     * Remove entries whose value (or a domain's resolved address) is one of these.
     *
     * @param  list<string>  $values  hostnames or addresses
     */
    public function removeValues(FirewallList $list, array $values, ?User $actor): int
    {
        $ids = collect($list->allow_entries ?? [])->filter(fn (array $entry) => in_array($entry['value'], $values, true)
            || array_intersect(array_keys($entry['addresses'] ?? []), $values) !== [])->pluck('id')->all();

        return $this->remove($list, $ids, $actor);
    }

    /**
     * Take out expired entries, and re-resolve domains: new addresses are
     * added, ones not seen for a week are dropped. Returns whether anything
     * changed.
     */
    public function refresh(FirewallList $list): bool
    {
        $entries = $list->allow_entries ?? [];
        $changed = false;
        $expired = collect($entries)->filter(fn (array $entry) => ! empty($entry['expires_at']) && now()->gte($entry['expires_at']));
        if ($expired->isNotEmpty()) {
            $entries = array_values(array_filter($entries, fn (array $entry) => ! $expired->contains('id', $entry['id'])));
            $this->audit->log('firewall.allow_expired', $list, ['expired' => $expired->pluck('value')->all()]);
            $changed = true;
        }
        foreach ($entries as $index => $entry) {
            if (($entry['type'] ?? null) !== 'domain') {
                continue;
            }
            $found = $this->domainAddresses($entry['value'], $list->family);
            if ($found === null) {
                continue;
            }
            $addresses = (array) ($entry['addresses'] ?? []);
            foreach ($found as $address) {
                $addresses[$address] = now()->toIso8601String();
            }
            $addresses = array_filter($addresses, fn (string $seen) => now()->subDays(self::GRACE_DAYS)->lt($seen));
            ksort($addresses);
            if (array_keys($addresses) !== array_keys((array) ($entry['addresses'] ?? []))) {
                $changed = true;
            }
            $entries[$index]['addresses'] = $addresses;
        }
        $list->forceFill(['allow_entries' => $entries])->save();

        return $changed;
    }

    /**
     * What one value is, and whether it may be added.
     *
     * @return array{value: string, type: ?string, addresses: list<string>, warnings: list<string>, error: ?string, needs_reason: bool}
     */
    private function inspectOne(FirewallList $list, string $value): array
    {
        $check = ['value' => $value, 'type' => null, 'addresses' => [], 'warnings' => [], 'error' => null, 'needs_reason' => false];
        $v6 = $list->family === 'ipv6';
        [$ip, $prefix] = str_contains($value, '/') ? explode('/', $value, 2) + [1 => ''] : [$value, null];

        if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            if (str_contains($ip, ':') !== $v6) {
                return ['error' => 'This list holds '.($v6 ? 'IPv6' : 'IPv4').' addresses.'] + $check;
            }
            if ($prefix !== null) {
                $bits = (int) $prefix;
                if (! ctype_digit($prefix) || $bits > ($v6 ? 128 : 32)) {
                    return ['error' => 'Not a valid subnet.'] + $check;
                }
                if ($bits < ($v6 ? self::WIDEST_V6 : self::WIDEST_V4)) {
                    return ['type' => 'cidr', 'error' => 'Too wide: a firewall allow list takes /'.($v6 ? self::WIDEST_V6 : self::WIDEST_V4).' or narrower.'] + $check;
                }
                if ($bits < ($v6 ? 64 : 24)) {
                    $check['warnings'][] = 'A wide subnet ('.number_format(2 ** min(32, ($v6 ? 128 : 32) - $bits)).'+ addresses) lets through every site on it.';
                    $check['needs_reason'] = true;
                }
            }
            $check['type'] = $prefix === null ? 'ip' : 'cidr';
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                $check['warnings'][] = 'A private or reserved address: it never goes through the External zone.';
            }
            $this->sharedWarning($check, [$value]);

            return $check;
        }

        try {
            $host = $this->normalizer->one($value);
        } catch (Throwable) {
            return ['error' => 'Not an address, subnet or hostname.'] + $check;
        }
        $check['value'] = $host;
        $check['type'] = 'domain';
        $addresses = $this->domainAddresses($host, $list->family);
        if ($addresses === null || $addresses === []) {
            $check['warnings'][] = "It doesn't resolve on the school's DNS right now; its addresses are added when it does.";
        } else {
            $check['addresses'] = $addresses;
            $this->sharedWarning($check, $addresses);
        }

        return $check;
    }

    /**
     * The addresses a domain resolves to through the probes, in this family
     * (sinkholed answers are left out), or null when nothing answered.
     *
     * @return list<string>|null
     */
    private function domainAddresses(string $host, string $family): ?array
    {
        $answers = $this->addresses->resolve($host);
        if (collect($answers)->every(fn (array $answer) => $answer['error'] !== null && $answer['error'] !== 'NXDOMAIN' && $answer['addresses'] === [])) {
            return null;
        }

        return collect($answers)->reject(fn (array $answer) => $answer['blocked'])->flatMap(fn (array $answer) => $answer['addresses'])
            ->filter(fn (string $address) => str_contains($address, ':') === ($family === 'ipv6'))
            ->unique()->sort()->values()->all();
    }

    /**
     * Warn, and ask for a reason, when an address is in a shared range.
     *
     * @param  array<string, mixed>  $check
     * @param  list<string>  $values
     */
    private function sharedWarning(array &$check, array $values): void
    {
        $ranges = array_merge_recursive(self::SHARED, (array) config('blocklists.shared_ranges', []));
        foreach ($values as $value) {
            $ip = explode('/', $value)[0];
            foreach ($ranges as $provider => $cidrs) {
                if (IpUtils::checkIp($ip, $cidrs)) {
                    $check['warnings'][] = "{$ip} belongs to {$provider}, which serves many unrelated sites from the same addresses: allowing it lets all of them through.";
                    $check['needs_reason'] = true;

                    return;
                }
            }
        }
    }
}
