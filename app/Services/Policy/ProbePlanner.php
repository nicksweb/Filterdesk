<?php

namespace App\Services\Policy;

use App\Models\FilterPolicy;
use App\Models\NetworkAssignment;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Recommends one probe container per filter policy so website checks see
 * exactly what that content filter does: the probe sits on one of the
 * policy's VLANs (macvlan on a tagged sub-interface) and resolves DNS
 * through that VLAN's gateway, which is where UniFi applies the filter.
 */
class ProbePlanner
{
    /** Existing compose services for the two built-in profiles. */
    private const BUILT_IN = ['student' => ['service' => 'probe-student', 'env' => 'STUDENT'], 'staff' => ['service' => 'probe-staff', 'env' => 'STAFF']];

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function plan(string $parent = 'eth0'): Collection
    {
        $parent = preg_match('/^[A-Za-z0-9_.-]{1,15}$/', $parent) ? $parent : 'eth0';

        return FilterPolicy::query()->where('enabled', true)->with('networkProfile')->ordered()->get()
            ->map(fn (FilterPolicy $policy) => $this->planFor($policy, $parent))
            ->filter()
            ->values();
    }

    /** @return array<string, mixed>|null */
    private function planFor(FilterPolicy $policy, string $parent): ?array
    {
        $networks = NetworkAssignment::query()->where('filter_policy_id', $policy->id)->orderBy('vlan')->get();
        if ($networks->isEmpty()) {
            return null;
        }

        $network = $networks->first(fn (NetworkAssignment $network) => $network->vlan && $this->subnet($network->subnet))
            ?? $networks->first();
        $subnet = $this->subnet($network->subnet);
        $key = $policy->networkProfile?->key ?? Str::slug($policy->slug, '_');
        $builtIn = self::BUILT_IN[$key] ?? null;
        $service = $builtIn['service'] ?? 'probe-'.Str::slug($policy->slug);
        $dockerNetwork = 'itr_'.Str::slug($policy->slug, '_').'_vlan';

        $plan = [
            'policy' => $policy,
            'network' => $network,
            'others' => $networks->reject(fn ($other) => $other->is($network))->values(),
            'key' => $key,
            'service' => $service,
            'built_in' => $builtIn !== null,
            'profile' => $policy->networkProfile,
            'probe_url' => "http://{$service}:3000",
            'docker_network' => $dockerNetwork,
            'subnet' => $subnet,
            'warnings' => [],
        ];

        if (! $network->vlan) {
            $plan['warnings'][] = "{$network->name} is untagged, so the probe needs a host interface on that network rather than a VLAN sub-interface.";
        }
        if (! $subnet) {
            $plan['warnings'][] = "The controller did not report a subnet for {$network->name}; fill in the addresses by hand.";
        }

        $parentInterface = $network->vlan ? "{$parent}.{$network->vlan}" : $parent;
        $plan['commands'] = $subnet
            ? "docker network create -d macvlan \\\n  --subnet={$subnet['network']} --gateway={$subnet['gateway']} \\\n  --ip-range={$subnet['probe_range']} \\\n  -o parent={$parentInterface} {$dockerNetwork}"
            : "docker network create -d macvlan --subnet=<network/prefix> --gateway=<gateway> \\\n  --ip-range=<small reserved range> -o parent={$parentInterface} {$dockerNetwork}";
        $plan['dns'] = $subnet['gateway'] ?? '<gateway>';
        $plan['env'] = $builtIn
            ? "{$builtIn['env']}_DOCKER_NETWORK={$dockerNetwork}\n{$builtIn['env']}_PROBE_DNS_SERVERS={$plan['dns']}"
            : null;
        $plan['compose'] = $builtIn
            ? "  {$service}:\n    networks:\n      probe_control: {}\n      {$key}_vlan:\n        gw_priority: 1"
            : "  {$service}:\n    image: it-edu-selfservice-probe:\${APP_IMAGE_TAG:-local}\n    env_file: .env\n    restart: unless-stopped\n    shm_size: 256mb\n    security_opt: [no-new-privileges:true]\n    cap_drop: [ALL]\n    environment:\n      PROBE_SHARED_KEY: \${PROBE_SHARED_KEY}\n      PROBE_DNS_SERVERS: {$plan['dns']}\n      BLOCK_PAGE_ADDRESSES: \${BLOCK_PAGE_ADDRESSES:-}\n      PROBE_REUSE_BROWSER: \"false\"\n    healthcheck:\n      test: [\"CMD\", \"curl\", \"-fsS\", \"http://127.0.0.1:3000/health\"]\n      interval: 20s\n    networks:\n      probe_control: {}\n      {$key}_vlan:\n        gw_priority: 1";
        $plan['compose_network'] = "  {$key}_vlan:\n    external: true\n    name: {$dockerNetwork}";

        return $plan;
    }

    /**
     * Parse a UniFi "gateway/prefix" subnet (for example 10.0.20.1/24) and
     * suggest a small range at the top of it for probe containers.
     *
     * @return array{gateway: string, network: string, probe_range: string, prefix: int}|null
     */
    public function subnet(?string $value): ?array
    {
        if (! $value || ! preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', $value, $match)) {
            return null;
        }

        $gateway = ip2long($match[1]);
        $prefix = (int) $match[2];
        if ($gateway === false || $prefix < 8 || $prefix > 29) {
            return null;
        }

        $mask = $prefix === 0 ? 0 : (~0 << (32 - $prefix)) & 0xFFFFFFFF;
        $network = $gateway & $mask;
        $broadcast = $network | (~$mask & 0xFFFFFFFF);
        // A /29 just below the top /29 of the subnet, so it holds neither the
        // broadcast address nor a gateway at .254 or similar; move further
        // down if the gateway still falls inside it.
        $range = ($broadcast - 15) & 0xFFFFFFF8;
        while ($gateway >= $range && $gateway <= $range + 7 && $range - 8 > $network) {
            $range -= 8;
        }

        return [
            'gateway' => $match[1],
            'network' => long2ip($network)."/{$prefix}",
            'probe_range' => long2ip($range).'/29',
            'prefix' => $prefix,
        ];
    }
}
