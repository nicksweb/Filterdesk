<?php

namespace App\Services\Policy;

use App\Models\FirewallList;
use App\Models\NetworkProfile;
use App\Services\Network\ManagesIpGroups;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * Works out what UniFi's zone-based firewall does with traffic from a
 * network to an address, from the rules and IP lists Filterdesk already
 * reads (cached for 5 minutes): the rules for the network's zone to
 * External, enabled ones only, in UniFi's order; the first rule whose
 * destination matches decides. Read only.
 *
 * Rules that match by something an address cannot be checked against (a
 * domain, region or app) are noted and skipped. Threat management (IPS)
 * and region blocking are separate in UniFi and not seen here.
 */
class FirewallEvaluator
{
    /** @var array{rules: list<array<string, mixed>>, groups: array<string, array<string, mixed>>, zones: list<array<string, mixed>>}|null */
    private ?array $data = null;

    private ?string $error = null;

    public function __construct(private readonly FirewallListPusher $pusher) {}

    /** Whether the provider's firewall can be read at all. */
    public function available(): bool
    {
        return $this->load() !== null;
    }

    public function error(): ?string
    {
        $this->load();

        return $this->error;
    }

    /**
     * The zone a network type's probe sits in, from its UniFi network.
     *
     * @return array{id: string, name: string}|null
     */
    public function zoneForProfile(NetworkProfile $profile): ?array
    {
        $data = $this->load();
        if (! $data || blank($profile->unifi_network_id)) {
            return null;
        }
        $zone = collect($data['zones'])->first(fn (array $zone) => in_array((string) $profile->unifi_network_id, $zone['network_ids'] ?? [], true));

        return $zone ? ['id' => $zone['id'], 'name' => $zone['name']] : null;
    }

    /** @return list<array{id: string, name: string, networks: list<string>}> */
    public function zones(): array
    {
        return $this->load()['zones'] ?? [];
    }

    /**
     * What the firewall does with traffic from a zone to an address on a
     * port (443 by default).
     *
     * @return array{decision: string, rule: ?array<string, mixed>, via: ?string, blocks: list<array{name: string, index: int, via: string}>, skipped: list<string>, allow_lists: list<string>}
     *                                                                                                                                                                                          decision: block, allow (a rule allows it), default (no rule matched), unknown (UniFi could not be read)
     */
    public function evaluate(string $address, string $zoneId, int $port = 443): array
    {
        $result = ['decision' => 'unknown', 'rule' => null, 'via' => null, 'blocks' => [], 'skipped' => [], 'allow_lists' => $this->allowListsHolding($address)];
        $data = $this->load();
        if (! $data || filter_var($address, FILTER_VALIDATE_IP) === false) {
            return $result;
        }
        $external = collect($data['zones'])->first(fn (array $zone) => strtolower($zone['name']) === 'external');
        $rules = collect($data['rules'])
            ->filter(fn (array $rule) => $rule['enabled'] && $rule['source'] === $zoneId && $rule['destination'] === ($external['id'] ?? null))
            ->sortBy('index')->values();

        foreach ($rules as $rule) {
            $match = $this->matches($rule['match'] ?? ['kind' => 'any'], $address, $port, $data['groups']);
            if ($match === null) {
                $result['skipped'][] = $rule['name'].' ('.($rule['match']['label'] ?? 'other').')';

                continue;
            }
            if ($match === false) {
                continue;
            }
            $action = strtoupper((string) $rule['action']);
            if (in_array($action, ['BLOCK', 'REJECT', 'DROP'], true)) {
                $result['blocks'][] = ['name' => $rule['name'], 'index' => $rule['index'], 'via' => $match];
            }
            if ($result['rule'] === null) {
                $result['rule'] = $rule;
                $result['via'] = $match;
                $result['decision'] = in_array($action, ['BLOCK', 'REJECT', 'DROP'], true) ? 'block' : 'allow';
            }
        }
        // UniFi's own rules (allow all to External, and so on) are the default, not a decision someone made.
        if ($result['rule'] === null || ($result['decision'] === 'allow' && ! ($result['rule']['user'] ?? true))) {
            $result['decision'] = 'default';
            $result['rule'] = null;
            $result['via'] = null;
        }

        return $result;
    }

    /**
     * One plain sentence about an evaluation, for IT.
     *
     * @param  array<string, mixed>  $result  from evaluate()
     */
    public static function describe(array $result, string $address): string
    {
        return match ($result['decision']) {
            'block' => "{$address} is blocked by the firewall rule {$result['rule']['name']}".($result['via'] ? " ({$result['via']})" : '').'.',
            'allow' => "{$address} is let through by the firewall rule {$result['rule']['name']}".($result['via'] ? " ({$result['via']})" : '')
                .($result['blocks'] ? ', ahead of '.collect($result['blocks'])->pluck('name')->join(', ') : '').'.',
            'default' => "No firewall rule blocks {$address}.",
            default => "The firewall could not be checked for {$address}.",
        };
    }

    /**
     * UniFi IP lists that no firewall rule uses, named like they are meant
     * to allow or block something ("Whitelist VPS IPs…").
     *
     * @return list<array{id: string, name: string, count: int}>
     */
    public function unusedGroups(): array
    {
        $data = $this->load();
        if (! $data) {
            return [];
        }
        $used = collect($data['rules'])->map(fn (array $rule) => $rule['match']['group'] ?? null)->filter()->unique()->all();
        $ours = FirewallList::query()->get()->flatMap(fn (FirewallList $list) => collect($list->groups ?? [])->pluck('id'))->all();

        return collect($data['groups'])->reject(fn (array $group) => in_array($group['id'], $used, true) || in_array($group['id'], $ours, true))
            ->filter(fn (array $group) => ($group['count'] ?? 0) > 0)
            ->map(fn (array $group) => ['id' => $group['id'], 'name' => $group['name'], 'count' => (int) $group['count']])->values()->all();
    }

    /**
     * Allow lists (managed here) whose addresses include this one.
     *
     * @return list<string>
     */
    public function allowListsHolding(string $address): array
    {
        return FirewallList::query()->where('kind', 'allow')->where('enabled', true)->get()
            ->filter(fn (FirewallList $list) => collect($list->desiredEntries()['entries'])->contains(fn (string $entry) => self::contains($entry, $address)))
            ->pluck('name')->values()->all();
    }

    /** Whether an address or subnet covers an address. */
    public static function contains(string $entry, string $address): bool
    {
        return rescue(fn () => IpUtils::checkIp($address, $entry), false, false);
    }

    /**
     * Whether a rule's destination matches: a description of how (true), false, or null when it cannot be told.
     *
     * @param  array<string, mixed>  $match
     * @param  array<string, array<string, mixed>>  $groups
     */
    private function matches(array $match, string $address, int $port, array $groups): string|false|null
    {
        if (isset($match['ports']) && is_array($match['ports'])) {
            if ($match['ports'] === []) {
                return null;
            }
            if (! collect($match['ports'])->contains(fn (array $range) => $port >= $range[0] && $port <= $range[1])) {
                return false;
            }
        }

        $kind = $match['kind'] ?? 'unknown';
        if ($kind === 'any') {
            return 'any address';
        }
        if ($kind === 'group') {
            $group = $groups[$match['group'] ?? ''] ?? null;
            if ($group === null) {
                return null;
            }
            $hit = $this->groupHit($group, $address);
        } elseif ($kind === 'addresses') {
            $entry = collect($match['addresses'] ?? [])->first(fn (string $entry) => self::contains($entry, $address));
            $hit = $entry === null ? null : "matches {$entry}";
        } else {
            return null;
        }
        if (! empty($match['opposite'])) {
            return $hit === null ? 'not in its list' : false;
        }

        return $hit ?? false;
    }

    /** @param array<string, mixed> $group */
    private function groupHit(array $group, string $address): ?string
    {
        $entry = collect($group['items'] ?? [])->first(fn (string $entry) => self::contains($entry, $address));

        return $entry === null ? null : "the list {$group['name']} contains it".($entry !== $address ? " via {$entry}" : '');
    }

    /** @return array{rules: list<array<string, mixed>>, groups: array<string, array<string, mixed>>, zones: list<array<string, mixed>>}|null */
    private function load(): ?array
    {
        if ($this->data !== null || $this->error !== null) {
            return $this->data;
        }
        $manager = $this->pusher->manager();
        if (! $manager instanceof ManagesIpGroups) {
            $this->error = 'The network provider has no firewall to read.';

            return null;
        }
        try {
            return $this->data = [
                'rules' => $manager->firewallRules(),
                'groups' => collect($manager->ipGroups())->keyBy('id')->all(),
                'zones' => $manager->firewallZones(),
            ];
        } catch (Throwable $exception) {
            $this->error = 'UniFi\'s firewall could not be read: '.$exception->getMessage();

            return null;
        }
    }
}
