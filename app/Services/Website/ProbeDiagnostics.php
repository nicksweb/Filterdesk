<?php

namespace App\Services\Website;

use App\Models\NetworkAssignment;
use App\Models\NetworkProfile;
use App\Services\Policy\ProbePlanner;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A setup checklist per probe: is the container running, is it on its
 * VLAN, can it reach the gateway, resolve DNS through it and load a page,
 * is the network filtered, and is its address safe from DHCP? Each failed
 * step comes with what to do about it, so IT can get every probe working
 * from the page that shows it.
 */
class ProbeDiagnostics
{
    /** Results are kept for 15 minutes; probes:check refreshes them every 10. */
    private const TTL = 900;

    public function __construct(private readonly ProbePlanner $planner) {}

    /**
     * @return array{state: string, summary: string, checks: list<array{key: string, label: string, status: string, detail: string, fix: list<string>}>, identity: ?array<string, mixed>}
     */
    public function for(NetworkProfile $profile, bool $refresh = false): array
    {
        $key = "probe.diagnose.{$profile->id}";
        if ($refresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, self::TTL, fn (): array => $this->run($profile));
    }

    /**
     * Checklists for several probes, asking the uncached ones in parallel so
     * a slow or broken probe does not hold up the page.
     *
     * @param  iterable<NetworkProfile>  $profiles
     * @return array<int, array<string, mixed>> keyed by profile id
     */
    public function forAll(iterable $profiles, bool $refresh = false): array
    {
        $profiles = collect($profiles);
        $results = [];
        $missing = $profiles->filter(function (NetworkProfile $profile) use ($refresh, &$results): bool {
            if ($refresh) {
                Cache::forget("probe.diagnose.{$profile->id}");
            }
            $cached = Cache::get("probe.diagnose.{$profile->id}");
            if ($cached) {
                $results[$profile->id] = $cached;
            }

            return $cached === null;
        })->values();

        if ($missing->isNotEmpty()) {
            $responses = Http::pool(fn (Pool $pool) => $missing->map(fn (NetworkProfile $profile) => $pool->as((string) $profile->id)
                ->connectTimeout(2)->timeout(12)->withHeaders(['X-Probe-Key' => (string) config('services.probe.key')])
                ->get(rtrim($profile->probe_url, '/').'/diagnose'))->all());
            foreach ($missing as $profile) {
                $result = $this->run($profile, $this->interpret($profile, $responses[(string) $profile->id] ?? null));
                Cache::put("probe.diagnose.{$profile->id}", $result, self::TTL);
                $results[$profile->id] = $result;
            }
        }

        return $results;
    }

    /** The cached checklist, without asking the probe. */
    public function cached(NetworkProfile $profile): ?array
    {
        return Cache::get("probe.diagnose.{$profile->id}");
    }

    /** @return array<string, mixed> */
    private function run(NetworkProfile $profile, ?array $prefetched = null): array
    {
        $network = $profile->unifi_network_id ? NetworkAssignment::query()->with('filterPolicy')->where('provider_network_id', $profile->unifi_network_id)->first() : null;
        $subnet = $network ? $this->planner->subnet($network->subnet) : null;
        $expectedGateway = $subnet['gateway'] ?? null;
        $trunk = $this->planner->saved()['trunk'];
        $interface = $this->planner->saved()['interfaces'][$network?->provider_network_id] ?? ($network?->vlan ? "{$trunk}.{$network->vlan}" : $trunk);
        $vlanText = $network ? ($network->vlan ? "VLAN {$network->vlan}" : 'the untagged (native) network') : 'its network';
        $compose = 'docker compose -f compose.yml -f compose.proxy.yml -f compose.vlan.yml';
        $checks = [];

        // 1. The probe knows which network it is for.
        $checks[] = $network
            ? $this->check('network', 'Network chosen', 'ok', "{$network->name} ({$vlanText}).")
            : $this->check('network', 'Network chosen', 'fail', $profile->unifi_network_id ? 'The network set on this profile is not one UniFi reports.' : 'No network is set for this probe.', [
                'Set the UniFi network (or VLAN number) for this profile below, or plan it under Policy lists → Guided setup → Probe containers.',
            ]);

        // 2. The container answers.
        $report = $prefetched ?? $this->fetch($profile);
        if (! $report['ok']) {
            $checks[] = $this->check('running', 'Probe container running', 'fail', $report['message'], [
                "Check it is running on the Docker host: {$compose} ps",
                'If it is missing, add it to compose.vlan.yml (download it from Guided setup, or run: docker compose exec -T app php artisan probes:compose > compose.vlan.yml) and start it with the command above plus "up -d".',
                "Check the probe URL on this profile matches the service name, for example http://{$this->serviceFor($profile)}:3000.",
            ]);

            return $this->summarise($checks, null);
        }
        $checks[] = $this->check('running', 'Probe container running', 'ok', 'It answers the app.');
        $vlan = collect($report['interfaces'] ?? [])->firstWhere('default_route', true);
        $address = $vlan['ipv4'][0] ?? null;

        // 3. It is on its VLAN.
        if ($network && $expectedGateway) {
            $onVlan = ($report['gateway'] ?? null) === $expectedGateway;
            $checks[] = $onVlan
                ? $this->check('vlan', "On {$network->name}", 'ok', "Address {$address}, MAC ".($vlan['mac'] ?? '?').", gateway {$expectedGateway}.")
                : $this->check('vlan', "On {$network->name}", 'fail', 'Its traffic leaves through '.($report['gateway'] ?? 'an unknown gateway')." ({$address}), not {$network->name}'s gateway {$expectedGateway}, so it sees the Docker host's filtering instead.", [
                    'Under Guided setup → Probe containers, check the trunk interface and save.',
                    'Regenerate compose.vlan.yml, run the "docker network create" commands at its top once, add its .env lines, then restart: '.$compose.' up -d',
                    'Every later docker compose command must include -f compose.vlan.yml, or the probes fall back to Docker\'s own network.',
                ]);
        }

        // 4. Layer 2: the gateway answers on the VLAN.
        if ($network && $expectedGateway && ($report['gateway'] ?? null) === $expectedGateway) {
            $checks[] = ($report['gateway_arp'] ?? false) || ($report['gateway_tcp'] ?? false)
                ? $this->check('gateway', 'Gateway reachable', 'ok', "{$expectedGateway} answers on {$vlanText}.")
                : $this->check('gateway', 'Gateway reachable', 'fail', "{$expectedGateway} does not answer, so frames on {$vlanText} are not reaching the gateway and back.", array_values(array_filter([
                    $network->vlan ? "In UniFi, give the Docker host's switch port a profile that carries VLAN {$network->vlan} tagged ({$network->name})." : "Make sure {$interface} is cabled to {$network->name}.",
                    $network->vlan ? "On the Docker host, run: sudo bin/probe-interfaces --listen {$trunk}. VLAN {$network->vlan} should appear in the list; if it does not, the port or hypervisor is not passing it." : null,
                    'On Proxmox: leave the VM\'s network device without a VLAN tag, make the bridge VLAN aware (bridge-vids must include this VLAN), and turn off the firewall\'s MAC filter for the VM, which drops the probe\'s own MAC.',
                    'On VMware: the port group needs VLAN 4095 (trunk) plus Promiscuous mode, MAC changes and Forged transmits set to Accept. On Hyper-V: enable MAC address spoofing and a trunk on the VM\'s adapter.',
                    "In UniFi, check {$network->name} has no isolation or firewall rule dropping new devices.",
                ])));
        }

        // 5. DNS through the gateway.
        $dnsServers = $report['dns'] ?? [];
        if ($report['dns_ok'] ?? false) {
            $checks[] = $expectedGateway && ! in_array($expectedGateway, $dnsServers, true)
                ? $this->check('dns', 'DNS through the filtering gateway', 'warn', 'Lookups work, but go to '.implode(', ', $dnsServers)." instead of {$expectedGateway}, so they may not be filtered the way {$network?->name} is.", [
                    "Set this probe's DNS to {$expectedGateway}: for probe-student and probe-staff use STUDENT_PROBE_DNS_SERVERS / STAFF_PROBE_DNS_SERVERS in .env; for other probes PROBE_DNS_SERVERS in compose.vlan.yml. Then restart it.",
                ])
                : $this->check('dns', 'DNS through the filtering gateway', 'ok', 'Lookups go to '.implode(', ', $dnsServers).'.');
        } else {
            $checks[] = $this->check('dns', 'DNS through the filtering gateway', 'fail', 'Lookups through '.implode(', ', $dnsServers).' fail ('.($report['dns_error'] ?? 'no answer').').', [
                'Fix "Gateway reachable" first if it failed.',
                "Otherwise check UniFi allows DNS (port 53) from {$address} to {$expectedGateway} on {$network?->name}.",
            ]);
        }

        // 6. Internet.
        $checks[] = ($report['internet_ok'] ?? false)
            ? $this->check('internet', 'Internet access', 'ok', 'It can load web pages.')
            : $this->check('internet', 'Internet access', 'fail', 'It cannot load a web page ('.($report['internet_error'] ?? 'no answer').'), so every check from it fails.', [
                'Fix the steps above first if any failed.',
                "Otherwise {$network?->name} probably blocks internet access (common for IoT and camera networks). In UniFi, allow {$address} out on ports 80 and 443, or test from a network that reflects real users.",
            ]);

        // 7. Filtered by a content filter.
        if ($network) {
            $policy = $network->filterPolicy;
            $checks[] = $policy
                ? $this->check('filtered', 'Network is filtered', 'ok', "{$network->name} is managed by {$policy->name} (".$policy->typeLabel().').')
                : $this->check('filtered', 'Network is filtered', 'warn', "No policy covers {$network->name}, so this probe shows sites unfiltered. That is fine for comparison, but it does not stand for any policy.", [
                    'Choose a network a content filter covers (see Policy lists → Networks & filters), or map this one to a policy there.',
                ]);
        }

        // 8. Address outside DHCP.
        if ($network && $address && $network->dhcp_start && $network->dhcp_stop) {
            $ip = ip2long(explode('/', $address)[0]);
            $inside = $ip !== false && $ip >= ip2long($network->dhcp_start) && $ip <= ip2long($network->dhcp_stop);
            $checks[] = $inside
                ? $this->check('dhcp', 'Address outside DHCP', 'warn', "{$address} is inside {$network->name}'s DHCP range ({$network->dhcp_start}–{$network->dhcp_stop}), so UniFi could give it to another device.", [
                    "In UniFi → Networks → {$network->name}, end the DHCP range below ".explode('/', $address)[0].'.',
                ])
                : $this->check('dhcp', 'Address outside DHCP', 'ok', "{$address} is outside the DHCP range.");
        }

        // 9. Switched on.
        $working = collect($checks)->every(fn (array $check) => $check['status'] !== 'fail');
        $checks[] = $profile->enabled
            ? $this->check('enabled', 'Switched on for checks', $working ? 'ok' : 'warn', $working ? 'Website checks include this probe.' : 'Checks include this probe, but it is failing, so its column shows errors.', $working ? [] : ['Fix the failed steps, or untick On until it works.'])
            : $this->check('enabled', 'Switched on for checks', $working ? 'todo' : 'skip', $working ? 'Everything above passes. Tick On and save to include it in website checks.' : 'Leave it off until the steps above pass.', $working ? ['Tick On for this profile and save.'] : []);

        return $this->summarise($checks, $report);
    }

    /** @return array<string, mixed> */
    private function fetch(NetworkProfile $profile): array
    {
        try {
            $response = Http::connectTimeout(2)->timeout(12)->withHeaders(['X-Probe-Key' => (string) config('services.probe.key')])
                ->get(rtrim($profile->probe_url, '/').'/diagnose');
        } catch (Throwable) {
            $response = null;
        }

        return $this->interpret($profile, $response);
    }

    /** @return array<string, mixed> */
    private function interpret(NetworkProfile $profile, mixed $response): array
    {
        if (! $response instanceof Response) {
            return ['ok' => false, 'message' => "The app cannot reach {$profile->probe_url}."];
        }
        if ($response->status() === 404) {
            return ['ok' => false, 'message' => 'This probe image is too old to run setup checks. Rebuild it (docker compose … build probe-student) and restart.'];
        }
        $body = $response->json();
        if (! $response->successful() || ! is_array($body)) {
            return ['ok' => false, 'message' => 'The probe answered with HTTP '.$response->status().'. Check PROBE_SHARED_KEY is the same for the app and the probe.'];
        }

        return ['ok' => true] + $body;
    }

    private function serviceFor(NetworkProfile $profile): string
    {
        return parse_url($profile->probe_url, PHP_URL_HOST) ?: 'probe-'.$profile->key;
    }

    /**
     * @param  list<string>  $fix
     * @return array{key: string, label: string, status: string, detail: string, fix: list<string>}
     */
    private function check(string $key, string $label, string $status, string $detail, array $fix = []): array
    {
        return compact('key', 'label', 'status', 'detail', 'fix');
    }

    /**
     * @param  list<array<string, mixed>>  $checks
     * @return array<string, mixed>
     */
    private function summarise(array $checks, ?array $report): array
    {
        $failed = collect($checks)->where('status', 'fail');
        $warnings = collect($checks)->where('status', 'warn');
        $todo = collect($checks)->where('status', 'todo');
        [$state, $summary] = match (true) {
            $failed->isNotEmpty() => ['fail', 'Not working yet: '.$failed->pluck('label')->map(fn ($label) => strtolower($label))->join(', ', ' and ').'.'],
            $todo->isNotEmpty() => ['todo', 'Ready. Switch it on to include it in checks.'],
            $warnings->isNotEmpty() => ['warn', 'Working, with '.$warnings->count().' thing(s) to check.'],
            default => ['ok', 'Working.'],
        };

        return ['state' => $state, 'summary' => $summary, 'checks' => $checks, 'identity' => $report];
    }
}
