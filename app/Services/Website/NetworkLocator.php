<?php

namespace App\Services\Website;

use App\Models\FilterPolicy;
use App\Models\NetworkAssignment;
use App\Models\NetworkProfile;
use App\Services\Policy\ProbePlanner;

/**
 * Works out which UniFi network a visitor is on from their IP address and
 * the subnets read from UniFi, and so the filtering they should be getting.
 * It needs the visitor's real address: behind a reverse proxy, that proxy
 * must be in TRUSTED_PROXIES. Only an aid; nothing is decided by it.
 */
class NetworkLocator
{
    public function __construct(private readonly ProbePlanner $planner) {}

    /**
     * @return array{network: NetworkAssignment, policy: ?FilterPolicy, type: ?string, profile: ?NetworkProfile}|null
     */
    public function locate(?string $ip): ?array
    {
        $address = $ip && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? ip2long($ip) : false;
        if ($address === false) {
            return null;
        }

        $best = null;
        foreach (NetworkAssignment::query()->with('filterPolicy')->whereNotNull('subnet')->get() as $network) {
            $subnet = $this->planner->subnet($network->subnet);
            if (! $subnet) {
                continue;
            }
            [$base, $prefix] = explode('/', $subnet['network']);
            $mask = (~0 << (32 - (int) $prefix)) & 0xFFFFFFFF;
            // The most specific subnet wins if two overlap.
            if (($address & $mask) === (ip2long($base) & $mask) && (! $best || (int) $prefix > $best['prefix'])) {
                $best = ['network' => $network, 'prefix' => (int) $prefix];
            }
        }
        if (! $best) {
            return null;
        }

        $network = $best['network'];
        $policy = $network->filterPolicy;
        $profiles = NetworkProfile::query()->where('enabled', true)->orderBy('sort_order')->get();
        $profile = $profiles->firstWhere('unifi_network_id', $network->provider_network_id)
            ?? ($policy ? $profiles->firstWhere('audience', $policy->audience) : null);

        return ['network' => $network, 'policy' => $policy, 'type' => $policy?->audience, 'profile' => $profile];
    }
}
