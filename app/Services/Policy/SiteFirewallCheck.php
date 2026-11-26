<?php

namespace App\Services\Policy;

use App\Models\NetworkProfile;
use App\Services\Website\SiteAddresses;
use App\Support\Reach;
use Illuminate\Support\Collection;

/**
 * What stands between a network and a site apart from the content filter:
 * whether the name resolves on that network's DNS (or is sinkholed),
 * whether a connection to its address gets a reply, and which UniFi
 * firewall rule decides that address for the network's zone. Used before a
 * quick allow or block and when approving a request, so IT doesn't add a
 * domain allow that can't work.
 */
class SiteFirewallCheck
{
    /** Hostnames checked at most per call; each one asks every probe. */
    public const LIMIT = 8;

    public function __construct(private readonly SiteAddresses $addresses, private readonly FirewallEvaluator $firewall) {}

    /**
     * @param  list<string>  $hosts
     * @return array{hosts: array<string, array{verdict: string, summary: string, networks: list<array<string, mixed>>, allow_lists: list<string>, blocked_addresses: list<string>}>, skipped: list<string>, firewall: bool}
     *                                                                                                                                                                                                                       verdict: firewall, dns_missing, dns_blocked, unreachable, allowed (nothing here blocks it), unknown
     */
    public function check(array $hosts, ?Reach $scope = null, bool $connect = true): array
    {
        $profiles = $this->profiles($scope);
        $result = ['hosts' => [], 'skipped' => array_values(array_slice($hosts, self::LIMIT)), 'firewall' => $this->firewall->available()];
        foreach (array_slice(array_values(array_unique($hosts)), 0, self::LIMIT) as $host) {
            $result['hosts'][$host] = $this->one($host, $profiles, $connect);
        }

        return $result;
    }

    /**
     * @param  Collection<int, NetworkProfile>  $profiles
     * @return array{verdict: string, summary: string, rule: ?string, networks: list<array<string, mixed>>, allow_lists: list<string>, blocked_addresses: list<string>}
     */
    private function one(string $host, Collection $profiles, bool $connect): array
    {
        $networks = [];
        $blockedAddresses = [];
        $allowLists = [];
        foreach ($this->addresses->resolve($host, $profiles, $connect) as $answer) {
            $zone = $answer['profile'] ? $this->firewall->zoneForProfile($answer['profile']) : null;
            $rows = [];
            foreach (array_slice($answer['addresses'], 0, 4) as $address) {
                $evaluation = $zone && ! $answer['blocked'] ? $this->firewall->evaluate($address, $zone['id']) : null;
                if (($evaluation['decision'] ?? null) === 'block') {
                    $blockedAddresses[] = $address;
                }
                $allowLists = [...$allowLists, ...($evaluation['allow_lists'] ?? $this->firewall->allowListsHolding($address))];
                $rows[] = ['address' => $address, 'evaluation' => $evaluation, 'connect' => $answer['connect'][$address] ?? null];
            }
            $networks[] = ['network' => $answer['network'], 'zone' => $zone['name'] ?? null, 'resolver' => $answer['resolver'], 'blocked' => $answer['blocked'],
                'error' => $answer['error'], 'addresses' => $rows];
        }

        $resolved = collect($networks)->filter(fn (array $network) => $network['addresses'] !== []);
        $verdict = match (true) {
            $networks === [] => 'unknown',
            $resolved->isEmpty() && collect($networks)->every(fn (array $network) => $network['blocked']) => 'dns_blocked',
            $resolved->isEmpty() => 'dns_missing',
            $blockedAddresses !== [] => 'firewall',
            $resolved->every(fn (array $network) => collect($network['addresses'])->every(fn (array $row) => $row['connect'] === 'timeout')) => 'unreachable',
            default => 'allowed',
        };
        $first = collect($networks)->flatMap(fn (array $network) => $network['addresses'])->first(fn (array $row) => ($row['evaluation']['decision'] ?? null) === 'block');

        return [
            'verdict' => $verdict,
            'summary' => match ($verdict) {
                'firewall' => "{$host} ({$first['address']}) is blocked by the firewall rule {$first['evaluation']['rule']['name']}".($first['evaluation']['via'] ? " ({$first['evaluation']['via']})" : '').'. A content filter allow won\'t open it.',
                'dns_missing' => "{$host} doesn't resolve on the school's DNS, so no allow will open it.",
                'dns_blocked' => "{$host} is blocked by DNS on every network checked (the content filter's block answer).",
                'unreachable' => "No reply from {$host} (".$resolved->flatMap(fn (array $network) => collect($network['addresses'])->pluck('address'))->unique()->join(', ').'): the connection is being dropped, usually by a firewall, but no UniFi rule here matches it. Check threat management (IPS), region blocking and the gateway.',
                'allowed' => "Nothing outside the content filter blocks {$host}.",
                default => "{$host} couldn't be checked: no probe answered.",
            },
            'rule' => $first['evaluation']['rule']['name'] ?? null,
            'networks' => $networks,
            'allow_lists' => array_values(array_unique($allowLists)),
            'blocked_addresses' => array_values(array_unique($blockedAddresses)),
        ];
    }

    /** @return Collection<int, NetworkProfile> */
    private function profiles(?Reach $scope): Collection
    {
        $profiles = NetworkProfile::query()->where('enabled', true)->whereNotNull('probe_url')->orderBy('sort_order')->get();
        if ($scope && ! $scope->siteWide) {
            $matching = $profiles->filter(fn (NetworkProfile $profile) => in_array($profile->audience, $scope->types, true))->values();

            return $matching->isNotEmpty() ? $matching : $profiles;
        }

        return $profiles;
    }
}
