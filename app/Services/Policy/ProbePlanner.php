<?php

namespace App\Services\Policy;

use App\Models\FilterPolicy;
use App\Models\NetworkAssignment;
use App\Models\NetworkProfile;
use App\Services\SettingsRepository;
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
    /** @var array<string, string> */
    private array $interfaces = [];

    /** @var array<string, string> network id => fixed IPv4 chosen by an admin */
    private array $addresses = [];

    private const BUILT_IN = ['student' => ['service' => 'probe-student', 'env' => 'STUDENT'], 'staff' => ['service' => 'probe-staff', 'env' => 'STAFF']];

    /** A VPN or remote-user network: no VLAN on the trunk, so no probe. */
    public static function isVpn(NetworkAssignment $network): bool
    {
        if (str_contains(strtolower((string) $network->purpose), 'vpn')) {
            return true;
        }

        return ! $network->vlan && (bool) preg_match('/(^|[^a-z])(vpn|wg|wireguard|openvpn|ipsec|l2tp|remote[- ]?user)([^a-z]|$)/i', (string) $network->name);
    }

    /** Stands in for the trunk until it is set, so the commands cannot run as pasted. */
    public const TRUNK_PLACEHOLDER = '<trunk>';

    /** A Linux interface name (at most 15 characters). */
    public const INTERFACE_PATTERN = '/^[A-Za-z0-9_.@-]{1,15}$/';

    public function __construct(private readonly SettingsRepository $settings) {}

    /**
     * Saved probe settings: the default trunk interface ('' until set), an interface per
     * controller network that overrides it, and networks given extra probes.
     *
     * @return array{trunk: string, interfaces: array<string, string>, extra: list<string>}
     */
    public function saved(): array
    {
        // No default: the app runs in a container and cannot see the host's interface
        // names, and a guess (eth0) fails on hosts with ens18, eno1, enp3s0 and so on.
        $trunk = (string) $this->settings->get('probes.trunk', '');

        return [
            'trunk' => preg_match(self::INTERFACE_PATTERN, $trunk) ? $trunk : '',
            'interfaces' => array_filter((array) $this->settings->get('probes.interfaces', []), fn ($value) => is_string($value) && preg_match(self::INTERFACE_PATTERN, $value)),
            'extra' => array_values(array_filter((array) $this->settings->get('probes.extra', []), 'is_string')),
            'addresses' => array_filter((array) $this->settings->get('probes.addresses', []), fn ($value) => is_string($value) && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)),
            'skip' => array_values(array_filter((array) $this->settings->get('probes.skip', []), 'is_string')),
        ];
    }

    /**
     * @param  array{trunk: string, interfaces: array<string, string>, extra: list<string>}  $values
     */
    public function save(array $values): void
    {
        $this->settings->set('probes.trunk', $values['trunk']);
        $this->settings->set('probes.interfaces', array_filter($values['interfaces'], fn ($value) => filled($value)));
        $this->settings->set('probes.extra', array_values($values['extra']));
        if (array_key_exists('skip', $values)) {
            $this->settings->set('probes.skip', array_values($values['skip']));
        }
        if (array_key_exists('addresses', $values)) {
            $this->settings->set('probes.addresses', array_filter($values['addresses'], fn ($value) => filled($value)));
        }
    }

    /**
     * One plan per enabled policy with a mapped network, plus one per extra
     * network picked for its own probe (a guest or BYOD network, or a second
     * VLAN worth testing on its own). Each probe's host interface is the one
     * saved for its network, otherwise <trunk>.<vlan> (or the trunk itself
     * for an untagged network).
     *
     * @param  string|null  $parent  default trunk; null uses the saved one
     * @param  list<string>|null  $extraNetworkIds  null uses the saved extra networks
     * @return Collection<int, array<string, mixed>>
     */
    public function plan(?string $parent = null, ?array $extraNetworkIds = null): Collection
    {
        $saved = $this->saved();
        $parent = $parent !== null && preg_match(self::INTERFACE_PATTERN, $parent) ? $parent : $saved['trunk'];
        $parent = $parent !== '' ? $parent : self::TRUNK_PLACEHOLDER;
        $extraNetworkIds ??= $saved['extra'];
        $this->interfaces = $saved['interfaces'];
        $this->addresses = $saved['addresses'];

        // 1. Every profile that names its network (a VLAN typed in the installer,
        //    or set under Settings → Network profiles) gets its probe on that network.
        $pinned = NetworkProfile::query()->whereNotNull('unifi_network_id')->where('unifi_network_id', '!=', '')->orderBy('sort_order')->orderBy('id')->get()
            ->map(function (NetworkProfile $profile) use ($parent): ?array {
                $network = NetworkAssignment::query()->with('filterPolicy')->where('provider_network_id', $profile->unifi_network_id)->first();
                if (! $network) {
                    return null;
                }
                $policy = $network->filterPolicy && ! $network->filterPolicy->isLayer() ? $network->filterPolicy : null;

                return $this->planFor($policy, $network, collect(), $parent, $profile);
            })
            ->filter()->values();

        // 2. Every other policy with a mapped network gets a probe on one of them.
        $covered = $pinned->map(fn (array $plan) => $plan['network']->provider_network_id)->all();
        $takenKeys = $pinned->pluck('key')->all();
        $policies = FilterPolicy::query()->main()->where('enabled', true)->with('networkProfile')->ordered()->get()
            ->map(function (FilterPolicy $policy) use ($parent, $covered, $takenKeys): ?array {
                // VPN and remote-user networks have no VLAN to put a probe on.
                $networks = NetworkAssignment::query()->where('filter_policy_id', $policy->id)->orderBy('vlan')->get()->reject(fn (NetworkAssignment $network) => self::isVpn($network))->values();
                if ($networks->isEmpty() || $networks->contains(fn (NetworkAssignment $network) => in_array($network->provider_network_id, $covered, true))) {
                    return null;
                }
                $network = $networks->first(fn (NetworkAssignment $network) => $network->vlan && $this->subnet($network->subnet)) ?? $networks->first();
                $key = $policy->networkProfile?->key;
                $key = $key && ! in_array($key, $takenKeys, true) ? $key : Str::slug($policy->slug, '_');
                if (in_array($key, $takenKeys, true) || isset(self::BUILT_IN[$key]) && $key !== $policy->networkProfile?->key) {
                    $key .= $network->vlan ? "_vlan{$network->vlan}" : '_policy';
                }

                return $this->planFor($policy, $network, $networks->reject(fn ($other) => $other->is($network))->values(), $parent, null, $key);
            })
            ->filter();
        $plans = $pinned->concat($policies);

        // 3. Extra networks ticked for their own probe.
        $covered = $plans->map(fn (array $plan) => $plan['network']->provider_network_id)->all();
        $extras = NetworkAssignment::query()->with('filterPolicy')->whereIn('provider_network_id', $extraNetworkIds)->orderBy('vlan')->get()
            ->reject(fn (NetworkAssignment $network) => in_array($network->provider_network_id, $covered, true))
            ->map(fn (NetworkAssignment $network) => $this->planFor(null, $network, collect(), $parent));

        return $plans->concat($extras)->values();
    }

    /**
     * The plans that get a probe: every plan except those whose network an
     * admin chose to leave without one (a network the host cannot reach, or
     * a policy not worth testing on its own).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function probes(?string $parent = null, ?array $extraNetworkIds = null): Collection
    {
        $skip = $this->saved()['skip'];

        return $this->plan($parent, $extraNetworkIds)->reject(fn (array $plan) => in_array($plan['network']->provider_network_id, $skip, true))->values();
    }

    /**
     * The COMPOSE_FILE line for .env: compose.yml, the proxy override when
     * the app is reached through a reverse proxy, and compose.vlan.yml.
     */
    public function composeFileLine(bool $proxy): string
    {
        return 'COMPOSE_FILE=compose.yml'.($proxy ? ':compose.proxy.yml' : '').':compose.vlan.yml';
    }

    /** Whether the plans can be used as they are: the trunk is set and every probe has a subnet. */
    public function ready(Collection $plans): bool
    {
        return $plans->isNotEmpty() && $plans->every(fn (array $plan) => ! str_contains($plan['commands'], '<'));
    }

    /**
     * The whole compose.vlan.yml for a set of plans, with the steps on the
     * Docker host as comments at the top. Nothing goes in .env except the
     * COMPOSE_FILE line: every value a probe needs is in this file.
     *
     * @param  Collection<int, array<string, mixed>>  $plans
     */
    public function composeFile(Collection $plans, bool $proxy = false, bool $remember = true): string
    {
        $header = ['# compose.vlan.yml, generated by Filterdesk (Policies → Guided setup).',
            '# Regenerate it after changing anything there rather than editing it by hand.', '#'];
        if (! $this->ready($plans)) {
            $header[] = '# NOT READY: '.(str_contains($plans->pluck('commands')->join(''), self::TRUNK_PLACEHOLDER)
                ? 'set the trunk interface in Guided setup first (run bin/probe-interfaces on this host to find it).'
                : 'a network has no subnet from UniFi; fill in the <...> values by hand.');
            $header[] = '# The commands below contain <...> on purpose, so they fail if run as they are.';
            $header[] = '#';
        }
        $header[] = '# Probes:';
        foreach ($plans as $plan) {
            $header[] = "#   {$plan['service']} → {$plan['interface']}".($plan['network']->vlan ? " (VLAN {$plan['network']->vlan}" : ' (untagged').($plan['subnet'] ? ", {$plan['subnet']['network']}, DNS {$plan['dns']})" : ')');
        }
        $header[] = '#';
        $header[] = '# 1. On the Docker host, create one macvlan network per probe (once):';
        foreach ($plans as $plan) {
            foreach (explode("\n", $plan['commands']) as $line) {
                $header[] = '#    '.$line;
            }
        }
        // Networks an earlier file created that this one no longer uses (a probe left out or renamed).
        $previous = array_values(array_filter((array) $this->settings->get('probes.generated_networks', []), 'is_string'));
        $current = $plans->pluck('docker_network')->values()->all();
        foreach (array_diff($previous, $current) as $unused) {
            $header[] = "#    No longer used (remove once, after docker compose up -d): docker network rm {$unused}";
        }
        if ($remember && $previous !== $current) {
            $this->settings->set('probes.generated_networks', $current);
        }
        $header[] = '#    Each line skips a network that already exists. If you changed the trunk or an address,';
        $header[] = '#    remove that network first (docker network rm <name>) so it is created again.';
        $header[] = '#    Check before going on; this must list '.$plans->pluck('docker_network')->join(', ').':';
        $header[] = '#    docker network ls --filter driver=macvlan';
        $header[] = '#';
        $header[] = '# 2. In .env, set this one line (replace any COMPOSE_FILE already there):';
        $header[] = '#    '.$this->composeFileLine($proxy);
        $header[] = '#    Every docker compose command (up, pull, logs, ps) then uses all of these files.';
        $header[] = '#    Don\'t use -f: it replaces COMPOSE_FILE, and a missing compose.proxy.yml means 502s.';
        $header[] = '#';
        $header[] = '# 3. Start: docker compose up -d';
        $header[] = '# 4. In Filterdesk, Settings → Network profiles: run each probe\'s checklist, then switch it on.';
        $header[] = '#';
        $header[] = '# Each probe\'s only way out is its VLAN (networks: !override, Docker Compose 2.24 or later),';
        $header[] = '# so it can never fall back to the server\'s own network and report the wrong filtering.';

        return implode("\n", [
            ...$header, '',
            'services:',
            ...$plans->map(fn (array $plan) => $plan['compose'])->all(),
            '',
            'networks:',
            ...$plans->map(fn (array $plan) => $plan['compose_network'])->all(),
        ])."\n";
    }

    /**
     * @param  Collection<int, NetworkAssignment>  $others
     * @return array<string, mixed>
     */
    private function planFor(?FilterPolicy $policy, NetworkAssignment $network, Collection $others, string $parent, ?NetworkProfile $pinnedProfile = null, ?string $key = null): array
    {
        $subnet = $this->subnet($network->subnet);
        $owner = $policy ?? $network->filterPolicy;
        $key = $pinnedProfile?->key ?? $key ?? ($policy
            ? ($policy->networkProfile?->key ?? Str::slug($policy->slug, '_'))
            : 'net_'.Str::slug($network->name, '_').($network->vlan ? "_{$network->vlan}" : ''));
        $builtIn = self::BUILT_IN[$key] ?? null;
        $slug = Str::slug($key);
        $service = $builtIn['service'] ?? 'probe-'.$slug;
        $dockerNetwork = 'itr_'.Str::slug($slug, '_').'_vlan';
        $vlanKey = Str::slug($key, '_').'_vlan';

        $plan = [
            'policy' => $policy,
            'network' => $network,
            'others' => $others,
            'key' => $key,
            'label' => $pinnedProfile?->name ?? ($policy ? $policy->name.' network' : $network->name.($network->vlan ? " (VLAN {$network->vlan})" : '')),
            'pinned' => $pinnedProfile !== null,
            'audience' => $owner?->audience ?? 'other',
            'service' => $service,
            'built_in' => $builtIn !== null,
            'profile' => $pinnedProfile ?? NetworkProfile::query()->where('key', $key)->first(),
            'probe_url' => "http://{$service}:3000",
            'docker_network' => $dockerNetwork,
            'subnet' => $subnet,
            'warnings' => [],
        ];

        if ($pinnedProfile && ! $network->filterPolicy) {
            $plan['warnings'][] = "{$pinnedProfile->name} is set to {$network->name}, but no content filter in UniFi covers {$network->name}, so this probe will see it unfiltered.";
        } elseif (! $policy) {
            $plan['warnings'][] = $owner
                ? "{$network->name} is filtered by {$owner->name}; this probe shows what that network sees."
                : "{$network->name} is not mapped to a policy here, so checks from it show the controller's filtering without anything to compare.";
        }
        if (! $subnet) {
            $plan['warnings'][] = "The controller did not report a subnet for {$network->name}; fill in the addresses by hand.";
        }

        $chosen = $this->interfaces[$network->provider_network_id] ?? null;
        $parentInterface = $chosen ?? ($network->vlan ? "{$parent}.{$network->vlan}" : $parent);
        $plan['interface'] = $parentInterface;
        $plan['interface_default'] = $network->vlan ? "{$parent}.{$network->vlan}" : $parent;
        $plan['interface_saved'] = $chosen !== null;
        if ($chosen === null && ! $network->vlan) {
            $plan['warnings'][] = "{$network->name} is untagged, so the probe uses {$parent} directly. If that interface is not on {$network->name}, set the right one below.";
        }
        // An admin-chosen address (for example one reserved on a DHCP server behind a relay)
        // replaces the suggested one; it must sit in the subnet and not be the gateway.
        $manual = $this->addresses[$network->provider_network_id] ?? null;
        if ($manual && (! $subnet || ! $this->inSubnet($manual, $subnet) || $manual === $subnet['gateway'])) {
            $plan['warnings'][] = "The address {$manual} set for this probe is not usable on {$network->name}".($subnet ? " ({$subnet['network']}, gateway {$subnet['gateway']})" : '').'; using the suggested one instead.';
            $manual = null;
        }
        $plan['address_source'] = $manual ? 'manual' : 'suggested';
        $ipRange = $manual ? "{$manual}/32" : ($subnet['probe_range'] ?? null);
        // Safe to run again: an existing network is left as it is.
        $plan['commands'] = "docker network inspect {$dockerNetwork} >/dev/null 2>&1 || ".($subnet
            ? "docker network create -d macvlan \\\n  --subnet={$subnet['network']} --gateway={$subnet['gateway']} \\\n  --ip-range={$ipRange} \\\n  -o parent={$parentInterface} {$dockerNetwork}"
            : "docker network create -d macvlan --subnet=<network/prefix> --gateway=<gateway> \\\n  --ip-range=<small reserved range> -o parent={$parentInterface} {$dockerNetwork}");
        $plan['dns'] = $subnet['gateway'] ?? '<gateway>';
        // Fixed address and MAC, so the probe is the same client in UniFi on every restart
        // and can be named or reserved there. The MAC is locally administered (02:…).
        $plan['ipv4'] = $manual ?? ($subnet ? long2ip(ip2long(explode('/', $subnet['probe_range'])[0]) + 1) : null);
        $plan['dhcp_mode'] = $network->dhcp_mode;
        if ($plan['ipv4'] && $network->dhcp_start && $network->dhcp_stop) {
            $ip = ip2long($plan['ipv4']);
            if ($ip >= ip2long($network->dhcp_start) && $ip <= ip2long($network->dhcp_stop)) {
                $plan['outside_pool'] = $this->addressOutsidePool($network);
                $plan['warnings'][] = "{$plan['ipv4']} is inside {$network->name}'s DHCP range ({$network->dhcp_start}–{$network->dhcp_stop}). Reserve it for this probe's MAC (Settings → Network profiles → Reserve in UniFi)"
                    .($plan['outside_pool'] ? ", or use {$plan['outside_pool']}, which is outside the pool" : ', or end the DHCP range below it').'.';
                $plan['dhcp_clash'] = true;
            }
        }
        if ($network->dhcp_mode === 'relay') {
            $plan['warnings'][] = "{$network->name} uses a DHCP relay, so addresses come from another DHCP server. Reserve {$plan['ipv4']} for this probe's MAC on that server, or exclude it there.";
        }
        $plan['mac'] = '02:'.implode(':', str_split(substr(md5($key.'|'.$network->provider_network_id), 0, 10), 2));
        $address = ($plan['ipv4'] ? "\n        ipv4_address: {$plan['ipv4']}" : '')."\n        mac_address: {$plan['mac']}";
        // Everything the probe needs is in compose.vlan.yml: the DNS server here, and only the
        // probe's VLAN as a way out (!override drops probe_egress from compose.yml), so it can
        // never leave through the server's network, whatever the Docker Engine version.
        $networks = "    networks: !override\n      probe_control: {}\n      {$vlanKey}:\n        gw_priority: 1{$address}";
        // dns: points the whole container (not only the probe's own lookups) at the VLAN's resolver.
        $containerDns = '    dns: ['.implode(', ', array_filter(array_map('trim', explode(',', (string) $plan['dns'])))).']';
        $plan['compose'] = $builtIn
            ? "  {$service}:\n    environment:\n      PROBE_DNS_SERVERS: {$plan['dns']}\n{$containerDns}\n{$networks}"
            : "  {$service}:\n    extends: { file: compose.yml, service: probe-student }\n    environment:\n      PROBE_DNS_SERVERS: {$plan['dns']}\n      ALLOWED_PRIVATE_HOSTS: \"\"\n{$containerDns}\n{$networks}";
        $plan['compose_network'] = "  {$vlanKey}:\n    external: true\n    name: {$dockerNetwork}";

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

    /** @param array{network: string} $subnet */
    private function inSubnet(string $ip, array $subnet): bool
    {
        [$base, $prefix] = explode('/', $subnet['network']);
        $mask = (~0 << (32 - (int) $prefix)) & 0xFFFFFFFF;

        return (ip2long($ip) & $mask) === (ip2long($base) & $mask) && ip2long($ip) !== ip2long($base) && ip2long($ip) !== (ip2long($base) | (~$mask & 0xFFFFFFFF));
    }

    /**
     * A usable address outside a network's DHCP pool (the highest free one
     * above the pool, otherwise the highest below it), skipping the gateway
     * and the network and broadcast addresses. Null when the pool fills the
     * subnet or the network has no known pool.
     */
    public function addressOutsidePool(NetworkAssignment $network): ?string
    {
        $subnet = $this->subnet($network->subnet);
        if (! $subnet || ! $network->dhcp_start || ! $network->dhcp_stop) {
            return null;
        }
        [$base, $prefix] = explode('/', $subnet['network']);
        $first = ip2long($base) + 1;
        $last = (ip2long($base) | (~((~0 << (32 - (int) $prefix)) & 0xFFFFFFFF) & 0xFFFFFFFF)) - 1;
        $gateway = ip2long($subnet['gateway']);
        $start = ip2long($network->dhcp_start);
        $stop = ip2long($network->dhcp_stop);
        foreach ([range($last, $stop + 1), $start - 1 >= $first ? range($start - 1, $first) : []] as $candidates) {
            foreach ($candidates as $candidate) {
                if ($candidate >= $first && $candidate <= $last && $candidate !== $gateway && ($candidate < $start || $candidate > $stop)) {
                    return long2ip($candidate);
                }
            }
        }

        return null;
    }
}
