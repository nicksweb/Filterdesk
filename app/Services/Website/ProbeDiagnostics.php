<?php

namespace App\Services\Website;

use App\Models\NetworkAssignment;
use App\Models\NetworkProfile;
use App\Services\Network\InspectionCertificate;
use App\Services\Network\ManagesReservations;
use App\Services\Network\NetworkProviderManager;
use App\Services\Policy\FirewallEvaluator;
use App\Services\Policy\ProbePlanner;
use App\Services\SettingsRepository;
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

        return $this->withReviews($profile, Cache::remember($key, self::TTL, fn (): array => $this->run($profile)));
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
            $cached = $refresh ? null : Cache::get("probe.diagnose.{$profile->id}");
            if ($cached) {
                $results[$profile->id] = $cached;
            }

            return $cached === null;
        })->values();

        if ($missing->isNotEmpty()) {
            $responses = Http::pool(fn (Pool $pool) => $missing->map(fn (NetworkProfile $profile) => $pool->as((string) $profile->id)
                ->connectTimeout(2)->timeout(12)->withHeaders(['X-Probe-Key' => (string) config('services.probe.key')])
                ->get(rtrim($profile->probe_url, '/').'/diagnose'))->all());
            $reports = $missing->mapWithKeys(fn (NetworkProfile $profile) => [$profile->id => $this->interpret($profile, $responses[(string) $profile->id] ?? null)]);
            // No probe name resolves from here: this container is not on the probe network (a setup
            // problem with it, not with the probes), so keep what was known instead of failing them all.
            if ($reports->count() > 1 && $reports->every(fn (array $report) => $report['unresolved'] ?? false)) {
                $this->unreachable = gethostname() ?: 'this container';

                return collect($profiles)->mapWithKeys(fn (NetworkProfile $profile) => [$profile->id => $this->cached($profile)])->filter()->all();
            }
            foreach ($missing as $profile) {
                $result = $this->run($profile, $reports[$profile->id]);
                Cache::put("probe.diagnose.{$profile->id}", $result, self::TTL);
                $results[$profile->id] = $result;
            }
        }

        return collect($results)->map(fn (array $result, int $id) => $this->withReviews($profiles->firstWhere('id', $id), $result))->all();
    }

    /** Set by forAll() when no probe's name resolved from this container. */
    public ?string $unreachable = null;

    /** The cached checklist, without asking the probe. */
    public function cached(NetworkProfile $profile): ?array
    {
        $result = Cache::get("probe.diagnose.{$profile->id}");

        return $result ? $this->withReviews($profile, $result) : null;
    }

    /** @return array<string, mixed> */
    private function run(NetworkProfile $profile, ?array $prefetched = null): array
    {
        $network = $profile->unifi_network_id ? NetworkAssignment::query()->with('filterPolicy')->where('provider_network_id', $profile->unifi_network_id)->first() : null;
        $subnet = $network ? $this->planner->subnet($network->subnet) : null;
        $expectedGateway = $subnet['gateway'] ?? null;
        $trunk = $this->planner->saved()['trunk'] ?: '<trunk>';
        $interface = $this->planner->saved()['interfaces'][$network?->provider_network_id] ?? ($network?->vlan ? "{$trunk}.{$network->vlan}" : $trunk);
        $vlanText = $network ? ($network->vlan ? "VLAN {$network->vlan}" : 'the untagged (native) network') : 'its network';
        // COMPOSE_FILE in .env lists every override, so plain commands use them all.
        $compose = 'docker compose';
        $checks = [];

        // 1. The probe knows which network it is for.
        $checks[] = $network
            ? $this->check('network', 'Network chosen', 'ok', "{$network->name} ({$vlanText}).")
            : $this->check('network', 'Network chosen', 'fail', $profile->unifi_network_id ? 'The network set on this profile is not one UniFi reports.' : 'No network is set for this probe.', [
                'Set the UniFi network (or VLAN number) for this profile below, or plan it under Policies → Guided setup → Probe containers.',
            ]);

        // 2. The container answers.
        $report = $prefetched ?? $this->fetch($profile);
        if (! $report['ok']) {
            // Guided setup was told to leave this network without a probe, or plans none for this profile.
            if ($network && in_array($network->provider_network_id, $this->planner->saved()['skip'], true)) {
                $checks[] = $this->check('running', 'Probe container running', 'fail', "No probe was chosen for {$network->name} in Guided setup, so compose.vlan.yml has none.", [
                    'Switch this profile off or delete it below, or untick No probe for it in Guided setup, step 3.',
                ]);

                return $this->summarise($checks, null);
            }
            if (! $this->planner->probes()->contains(fn (array $plan) => rtrim($plan['probe_url'], '/') === rtrim((string) $profile->probe_url, '/'))) {
                $checks[] = $this->check('running', 'Probe container running', 'fail', 'No probe in the generated compose.vlan.yml matches this profile ('.$profile->probe_url.'), so there is nothing to start.', [
                    'Delete this profile if you no longer need it, or set its network so Guided setup plans a probe for it.',
                ]);

                return $this->summarise($checks, null);
            }
            $checks[] = $this->check('running', 'Probe container running', 'fail', $report['message'], [
                "Check it is running on the Docker host: {$compose} ps",
                'If it is missing, regenerate compose.vlan.yml (Guided setup, step 3), check .env has COMPOSE_FILE=…:compose.vlan.yml, then docker compose up -d.',
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
                    'Under Guided setup, step 3, check the trunk interface and save.',
                    'Regenerate compose.vlan.yml, run the "docker network create" commands at its top once, then: '.$compose.' up -d',
                    'Make sure .env has one COMPOSE_FILE line that includes compose.vlan.yml, and do not add -f to commands (it replaces that list).',
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
                    $network->vlan ? "To see where it stops: sudo tcpdump -nn -e -i {$trunk} 'vlan {$network->vlan} and arp'. If only the probe's \"who-has {$expectedGateway}\" requests appear and no reply, the VLAN is not carried between this host and the gateway: check the switch port profile (and every switch on the way), and on Proxmox run 'bridge vlan show' on the host and confirm this VM's tap interface lists VLAN {$network->vlan}." : null,
                ])));
        }

        // 5. DNS through the gateway.
        $dnsServers = $report['dns'] ?? [];
        if ($report['dns_ok'] ?? false) {
            $checks[] = $expectedGateway && ! in_array($expectedGateway, $dnsServers, true)
                ? $this->check('dns', 'DNS through the filtering gateway', 'warn', 'Lookups work, but go to '.implode(', ', $dnsServers)." instead of {$expectedGateway}, so they may not be filtered the way {$network?->name} is.", [
                    "Set this probe's DNS to {$expectedGateway}: regenerate compose.vlan.yml in Guided setup (it sets PROBE_DNS_SERVERS for every probe), then docker compose up -d.",
                ])
                : $this->check('dns', 'DNS through the filtering gateway', 'ok', 'Lookups go to '.implode(', ', $dnsServers).'.');
        } else {
            $checks[] = $this->check('dns', 'DNS through the filtering gateway', 'fail', 'Lookups through '.implode(', ', $dnsServers).' fail ('.($report['dns_error'] ?? 'no answer').').', [
                'Fix "Gateway reachable" first if it failed.',
                "Otherwise check UniFi allows DNS (port 53) from {$address} to {$expectedGateway} on {$network?->name}.",
            ]);
        }

        // 6. Internet.
        $intercepted = collect((array) ($report['intercepted'] ?? []))->filter(fn ($item) => is_array($item) && filled($item['host'] ?? null));
        // Hosts inspected with the school's own uploaded inspection certificate (often www.google.com, for
        // SafeSearch) are expected: they don't count against the internet check, the other test sites decide it.
        $certificate = app(InspectionCertificate::class);
        $bySchoolCertificate = $intercepted->filter(fn ($item) => $certificate->matches($item['issuer'] ?? null));
        $otherInterception = $intercepted->reject(fn ($item) => $certificate->matches($item['issuer'] ?? null))->values();
        $loaded = collect((array) ($report['internet_results'] ?? []))->filter(fn ($item) => is_array($item) && ($item['ok'] ?? false))->pluck('host');
        $internetOk = ($report['internet_ok'] ?? false) || ($bySchoolCertificate->isNotEmpty() && $intercepted->count() === $bySchoolCertificate->count() && $loaded->isNotEmpty());
        $checks[] = $internetOk
            ? ($otherInterception->isEmpty()
                ? $this->check('internet', 'Internet access', 'ok', 'It can load web pages'.($loaded->isNotEmpty() ? ' ('.$loaded->join(', ').')' : '').'.'
                    .($bySchoolCertificate->isNotEmpty() ? ' HTTPS to '.$bySchoolCertificate->pluck('host')->join(', ').' is inspected with your school\'s certificate ('.$bySchoolCertificate->first()['issuer'].'), as expected, so other sites were used to test the connection.' : ''))
                : $this->check('internet', 'Internet access', 'warn', 'Internet works, but HTTPS to '.$otherInterception->map(fn ($item) => $item['host'].' is intercepted by the gateway (certificate from '.($item['issuer'] ?? 'unknown').')')->join('; ').'. A content filter, SafeSearch, ad blocking or a traffic rule is inspecting or blocking it on '.($network?->name ?? 'this network').'.', [
                    'If this network uses HTTPS inspection on purpose, upload its CA under Settings → HTTPS inspection: hosts inspected with it then count as expected, and devices can install it from '.route('certificate.show').'.',
                    'In UniFi, look at what acts on '.$otherInterception->pluck('host')->join(', ').' for this network: the content filter categories and SafeSearch, ad blocking, and traffic rules. Users there get the same certificate warning.',
                    'If that is intended, mark this reviewed.',
                ]))
            : $this->check('internet', 'Internet access', 'fail', 'It cannot load a web page ('.($report['internet_error'] ?? 'no answer').'), so every check from it fails.', [
                'Fix the steps above first if any failed.',
                "Otherwise {$network?->name} probably blocks internet access (common for IoT and camera networks). In UniFi, allow {$address} out on ports 80 and 443, or test from a network that reflects real users.",
            ]);

        // 6b. The browser resolves through the VLAN's DNS, not Docker's.
        $checks[] = match ($report['browser_dns'] ?? null) {
            'pinned' => $this->check('browser_dns', "Browser uses the VLAN's DNS", 'ok', 'Pages load through '.implode(', ', $dnsServers).', the same answers the check sees.'),
            'system' => $this->check('browser_dns', "Browser uses the VLAN's DNS", 'warn', "The probe's browser resolves names through the container's own DNS, not ".($expectedGateway ?? "the network's gateway").', so it can load sites a device on '.($network?->name ?? 'this network').' cannot, and does not see SafeSearch.', [
                'Regenerate compose.vlan.yml in Guided setup, step 3 (it sets PROBE_DNS_SERVERS and dns: for every probe), then docker compose up -d.',
            ]),
            default => $this->check('browser_dns', "Browser uses the VLAN's DNS", 'warn', "This probe image is from before 2026.12.18, so its browser resolves names through Docker's DNS instead of the VLAN's. It can load sites a device on ".($network?->name ?? 'this network').' cannot and report them as allowed.', [
                'Update the probe image on the Docker host: docker compose pull, then docker compose up -d.',
            ]),
        };

        // 6c. A site on the filter's block list is reported as blocked.
        if ($network?->filterPolicy && ($report['dns_ok'] ?? false)) {
            $checks[] = $this->blockedSiteCheck($profile, $network);
        }

        // 7. Filtered by a content filter.
        if ($network) {
            $policy = $network->filterPolicy;
            $checks[] = $policy
                ? $this->check('filtered', 'Network is filtered', 'ok', "{$network->name} is managed by {$policy->name} (".$policy->typeLabel().').')
                : $this->check('filtered', 'Network is filtered', 'warn', "No policy covers {$network->name}, so this probe shows sites unfiltered. That is fine for comparison, but it does not stand for any policy.", [
                    'Choose a network a content filter covers (see Policies → Networks & filters), or map this one to a policy there.',
                ]);
        }

        // 7b. The network's firewall zone is known, so UniFi's firewall rules can be checked for it.
        if ($network) {
            $firewall = app(FirewallEvaluator::class);
            if ($firewall->available()) {
                $zone = $firewall->zoneForProfile($profile);
                $checks[] = $zone
                    ? $this->check('firewall_zone', 'Firewall zone found', 'ok', "{$network->name} is in the {$zone['name']} zone, so website checks and Quick allow can check UniFi's firewall rules for it.")
                    : $this->check('firewall_zone', 'Firewall zone found', 'warn', (string) $firewall->zoneProblem($profile), [
                        'Check the network exists in UniFi under the same VLAN and name, then read UniFi again (Policies → Networks & filters → Refresh).',
                    ]);
            }
        }

        // 8. The address is safe from DHCP: outside the range, or reserved for this MAC.
        if ($network && $address) {
            $ip = explode('/', $address)[0];
            $mac = strtolower((string) ($vlan['mac'] ?? ''));
            $reservation = $this->reservations()[$mac] ?? null;
            $reserved = $reservation && $reservation['ip'] === $ip;
            $inside = $network->dhcp_start && $network->dhcp_stop && ip2long($ip) >= ip2long($network->dhcp_start) && ip2long($ip) <= ip2long($network->dhcp_stop);
            $outside = $this->planner->addressOutsidePool($network);
            $confirmed = ((array) app(SettingsRepository::class)->get('probes.dhcp_confirmed', []))[$profile->id] ?? null;
            $dhcpCheck = match (true) {
                $reserved => $this->check('dhcp', 'Address safe from DHCP', 'ok', "{$ip} is reserved for {$mac} in UniFi."),
                $network->dhcp_mode === 'relay' && $confirmed === "{$ip}|{$mac}" => $this->check('dhcp', 'Address safe from DHCP', 'ok', "{$ip} is reserved for {$mac} on {$network->name}'s DHCP server (confirmed here)."),
                $network->dhcp_mode === 'relay' => $this->check('dhcp', 'Address safe from DHCP', 'warn', "{$network->name} uses a DHCP relay, so another server hands out its addresses and could give {$ip} to another device.", [
                    "On that DHCP server, reserve {$ip} for MAC {$mac} (a DHCP reservation is fine), then confirm it here.",
                    'Or set a different address for this probe in Guided setup, step 3: one the DHCP server never hands out.',
                ], 'confirm_relay'),
                $inside => $this->check('dhcp', 'Address safe from DHCP', 'warn', "{$ip} is inside {$network->name}'s DHCP range ({$network->dhcp_start}–{$network->dhcp_stop}), so UniFi could give it to another device.", array_values(array_filter([
                    "Reserve in UniFi: pins {$ip} to MAC {$mac}, so UniFi never hands it out. Nothing else changes.",
                    $outside ? "Or use {$outside}, which is outside the pool. The probe's address changes, so regenerate compose.vlan.yml and recreate its Docker network afterwards." : null,
                    "Or end the DHCP range below {$ip} in UniFi → Networks → {$network->name}.",
                ])), 'reserve'),
                $network->dhcp_mode === 'none' => $this->check('dhcp', 'Address safe from DHCP', 'ok', "{$network->name} has no DHCP server, so {$ip} is only used by this probe."),
                default => $this->check('dhcp', 'Address safe from DHCP', 'ok', "{$ip} is outside the DHCP range."),
            };
            // What the Reserve / Use outside-pool buttons need.
            $checks[] = ['outside' => $network->dhcp_mode !== 'relay' ? $outside : null, 'network_id' => $network->provider_network_id, 'ip' => $ip, 'mac' => $mac] + $dhcpCheck;
        }

        // 9. Switched on.
        $working = collect($checks)->every(fn (array $check) => $check['status'] !== 'fail');
        $checks[] = $profile->enabled
            ? $this->check('enabled', 'Switched on for checks', $working ? 'ok' : 'warn', $working ? 'Website checks include this probe.' : 'Checks include this probe, but it is failing, so its column shows errors.', $working ? [] : ['Fix the failed steps, or untick On until it works.'])
            : $this->check('enabled', 'Switched on for checks', $working ? 'todo' : 'skip', $working ? 'Everything above passes. Tick On and save to include it in website checks.' : 'Leave it off until the steps above pass.', $working ? ['Tick On for this profile and save.'] : []);

        return $this->summarise($checks, $report);
    }

    /**
     * Ask the probe how its DNS answers a few names on the network's filter
     * block list. UniFi answers a blocked name with a block address; if the
     * probe does not recognise the answer, checks report blocked sites as
     * allowed, so offer to save that answer.
     *
     * @return array<string, mixed>
     */
    private function blockedSiteCheck(NetworkProfile $profile, NetworkAssignment $network): array
    {
        $policy = $network->filterPolicy;
        $filter = $policy->provider_reference ? $policy->name : null;
        $domains = collect($policy->current_blocklist ?? [])
            ->filter(fn ($domain) => is_string($domain) && str_contains($domain, '.') && ! str_contains($domain, '*') && ! str_contains($domain, '/'))
            ->take(3)->values();
        $label = 'Blocked sites are detected';
        if ($filter === null || $domains->isEmpty()) {
            return $this->check('blocked', $label, 'skip', "{$policy->name} has no blocked hostnames in UniFi to test with.");
        }

        $blockAddresses = app(ProbeSettings::class)->blockAddresses();
        $responses = Http::pool(fn (Pool $pool) => $domains->map(fn (string $domain) => $pool->as($domain)
            ->connectTimeout(2)->timeout(5)->withHeaders(['X-Probe-Key' => (string) config('services.probe.key')])
            ->post(rtrim($profile->probe_url, '/').'/resolve', ['host' => $domain, 'block_addresses' => $blockAddresses]))->all());
        $answers = $domains->mapWithKeys(fn (string $domain) => [$domain => $responses[$domain] ?? null]);

        if ($answers->every(fn ($response) => $response instanceof Response && $response->status() === 404)) {
            return $this->check('blocked', $label, 'warn', 'This probe image is from before 2026.12.18 and cannot run this step.', [
                'Update the probe image on the Docker host: docker compose pull, then docker compose up -d.',
            ]);
        }
        $results = $answers->map(fn ($response) => $response instanceof Response && $response->successful() ? (array) $response->json() : null)->filter();
        if ($results->isEmpty()) {
            return $this->check('blocked', $label, 'warn', 'The probe did not answer the test lookups.');
        }

        $blocked = $results->first(fn (array $result) => ($result['blocked'] ?? false) || ($result['error'] ?? null) === 'ENOTFOUND');
        if ($blocked) {
            $domain = $results->search($blocked);
            $answer = ($blocked['addresses'] ?? []) ? 'answers '.implode(', ', $blocked['addresses']) : 'has no answer';

            return $this->check('blocked', $label, 'ok', "{$domain} is on {$filter}'s block list, and this network's DNS {$answer}, which the probe reports as blocked.");
        }

        $domain = (string) $results->keys()->first();
        $addresses = array_values(array_filter((array) ($results[$domain]['addresses'] ?? []), 'is_string'));

        return ['addresses' => $addresses] + $this->check('blocked', $label, 'warn',
            "{$domain} is on {$filter}'s block list, but this network's DNS answers ".(implode(', ', $addresses) ?: 'nothing').', which the probe does not treat as blocked. Checks may report blocked sites as allowed.', array_values(array_filter([
                $addresses ? 'If '.implode(', ', $addresses).' is the answer your gateway gives for blocked sites, save it as a block answer.' : null,
                "Otherwise check in UniFi that {$filter} is on {$network->name}, is not paused, and blocks {$domain} at this time of day.",
            ])), $addresses ? 'block_answer' : null);
    }

    /** @return array<string, mixed> */
    private function fetch(NetworkProfile $profile): array
    {
        try {
            $response = Http::connectTimeout(2)->timeout(12)->withHeaders(['X-Probe-Key' => (string) config('services.probe.key')])
                ->get(rtrim($profile->probe_url, '/').'/diagnose');
        } catch (Throwable $exception) {
            $response = $exception;
        }

        return $this->interpret($profile, $response);
    }

    /** @return array<string, mixed> */
    private function interpret(NetworkProfile $profile, mixed $response): array
    {
        if (! $response instanceof Response) {
            $unresolved = $response instanceof Throwable && preg_match('/Could not resolve host|cURL error 6\b|getaddrinfo|Name or service not known/i', $response->getMessage()) === 1;

            return ['ok' => false, 'unresolved' => $unresolved, 'message' => $unresolved
                ? 'The name '.parse_url((string) $profile->probe_url, PHP_URL_HOST).' does not resolve from '.(gethostname() ?: 'the app').'. Check the probe container exists, and that this container is on the probe_control network (compose.yml).'
                : "The app cannot reach {$profile->probe_url}."];
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
    private function check(string $key, string $label, string $status, string $detail, array $fix = [], ?string $action = null): array
    {
        return compact('key', 'label', 'status', 'detail', 'fix', 'action') + ['outside' => null, 'network_id' => null, 'ip' => null, 'mac' => null];
    }

    /** @return array<string, array{ip: string, network_id: ?string, name: ?string}> */
    private function reservations(): array
    {
        return $this->reservations ??= Cache::remember('probe.reservations', 300, function (): array {
            $provider = rescue(fn () => app(NetworkProviderManager::class)->current(), null, false);

            return $provider instanceof ManagesReservations ? rescue(fn () => $provider->reservations(), [], false) : [];
        });
    }

    /** @var array<string, array<string, mixed>>|null */
    private ?array $reservations = null;

    /**
     * Steps an admin marked "reviewed, no action needed" stop counting
     * against the probe until their detail changes.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function withReviews(NetworkProfile $profile, array $result): array
    {
        $reviewed = (array) (((array) app(SettingsRepository::class)->get('probes.reviewed', []))[$profile->id] ?? []);
        $checks = collect($result['checks'])->map(function (array $check) use ($reviewed): array {
            if ($check['status'] === 'warn' && ($reviewed[$check['key']] ?? null) === md5($check['detail'])) {
                $check['status'] = 'reviewed';
                $check['detail'] = 'Reviewed, no action needed. '.$check['detail'];
            }
            $check['reviewable'] = in_array($check['status'], ['warn', 'reviewed'], true) && $check['key'] !== 'enabled';

            return $check;
        })->all();

        return $this->summarise($checks, $result['identity']);
    }

    /**
     * @param  list<array<string, mixed>>  $checks
     * @return array<string, mixed>
     */
    private function summarise(array $checks, ?array $report): array
    {
        $failed = collect($checks)->where('status', 'fail');
        $warnings = collect($checks)->where('status', 'warn');
        $reviewedCount = collect($checks)->where('status', 'reviewed')->count();
        $todo = collect($checks)->where('status', 'todo');
        [$state, $summary] = match (true) {
            $failed->isNotEmpty() => ['fail', 'Not working yet: '.$failed->pluck('label')->map(fn ($label) => strtolower($label))->join(', ', ' and ').'.'],
            $todo->isNotEmpty() => ['todo', 'Ready. Switch it on to include it in checks.'],
            $warnings->isNotEmpty() => ['warn', 'Working, with '.$warnings->count().' thing(s) to check.'],
            default => ['ok', 'Working.'.($reviewedCount ? " {$reviewedCount} item(s) reviewed." : '')],
        };

        return ['state' => $state, 'summary' => $summary, 'checks' => $checks, 'identity' => $report];
    }
}
