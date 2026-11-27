<?php

namespace App\Services\Network;

use App\Models\IntegrationConfig;
use App\Models\WebsiteRequest;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * UniFi Network via the official integration API (X-API-Key). Two connection
 * modes reach the same API:
 *
 * - local: a key created on the console (Network → Settings → Control Plane →
 *   Integrations), called at https://<console>/proxy/network/integration/v1.
 * - cloud: a Site Manager key from unifi.ui.com, called through Ubiquiti's
 *   connector at https://api.ui.com/v1/connector/consoles/<id>/proxy/network/integration/v1.
 *   Site Manager keys are rejected (HTTP 401) by the local endpoint.
 *
 * Content-filter domain lists are not exposed by the official integration
 * API (checked against Network 11.0). They are read from the Network
 * application's own v2 endpoint (/proxy/network/v2/api/site/<site>/content-filtering),
 * which accepts the same key. Writes use the same endpoint (PUT of the full
 * filter record with only the lists changed) and only happen when the
 * integration is in active mode; new filters are created with POST
 * .../content-filtering/create, as the Network settings page does; every write is read back and verified. If a
 * release changes the endpoint, calls fail with a clear message and IT can
 * still paste the lists by hand.
 */
class UniFiNetworkProvider implements ManagesIpGroups, ManagesReservations, NetworkProvider, ReadsContentFilters, WritesContentFilters
{
    /** Network purposes a content filter can apply to. */
    private const FILTERABLE_PURPOSES = ['corporate', 'guest', 'vlan-only', 'remote-user-vpn'];

    /** Waits between attempts after a connection error: about 30 s in all, the length of a DNS reload. */
    public const RETRY_DELAYS_MS = [2000, 5000, 10000, 13000];

    /** Until when (unix time) the gateway may still be reloading DNS after a content-filter save. */
    private const DNS_RELOAD_KEY = 'unifi.dns_reload_until';

    private function noteFilterSaved(): void
    {
        Cache::put(self::DNS_RELOAD_KEY, time() + 45, 60);
    }

    private ?string $siteReference = null;

    private const FIREWALL_CACHE = 'unifi.firewall.';

    /** Forget cached groups and rules after the app changes them. */
    private function forgetFirewall(): void
    {
        Cache::forget(self::FIREWALL_CACHE.'groups');
        Cache::forget(self::FIREWALL_CACHE.'policies');
    }

    public function __construct(private readonly IntegrationConfig $integration) {}

    public function capabilities(): array
    {
        return [
            'site_discovery' => true,
            'network_discovery' => true,
            'firewall_policies' => true,
            'content_filter_read' => true,
            'ip_groups' => true,
            'content_filter_write' => ($this->integration->mode ?? 'read_only') === 'active',
            // The official API currently has no portable content-filter domain
            // allowlist operation. Never claim automation without a supported API.
            'domain_allowlist' => false,
            'scheduled_domain_allowlist' => false,
        ];
    }

    public function domainRules(): DomainRules
    {
        $includesSubdomains = ($this->integration->configuration['entry_matching'] ?? 'exact') === 'includes_subdomains';

        return new DomainRules(
            entriesIncludeSubdomains: $includesSubdomains,
            supportsWildcards: false,
            supportsPaths: false,
            notes: [
                'UniFi content filtering is DNS based: it sees hostnames only, never URL paths, so a single page cannot be allowed on its own.',
                'Enter bare hostnames, one per line, with no scheme, path, port or wildcard (*.).',
                $includesSubdomains
                    ? 'This controller is configured as matching subdomains, so redundant subdomain entries are collapsed into their parent domain.'
                    : 'Each hostname is listed explicitly: the registrable domain and every subdomain the site needs (www, CDN, API and login hosts).',
                'Allow-list entries override category blocking for the networks the content filter applies to.',
            ],
        );
    }

    public const CLOUD_BASE_URL = 'https://api.ui.com';

    public function isCloud(): bool
    {
        return ($this->integration->configuration['connection'] ?? 'local') === 'cloud';
    }

    public function test(): array
    {
        if ($this->isCloud() && blank($this->integration->configuration['console_id'] ?? null)) {
            $consoles = $this->consoles();

            return $consoles === null
                ? ['ok' => false, 'message' => 'Site Manager rejected the API key. Create one at unifi.ui.com → API.']
                : ['ok' => false, 'message' => 'The Site Manager key works ('.count($consoles).' console(s) found). Choose a console under Discover, then save and test again.'];
        }

        $response = $this->client()->get($this->path('/sites'));

        if (! $response->successful()) {
            $code = (string) $response->json('code', '');

            return ['ok' => false, 'message' => match (true) {
                $code === 'device_offline' => 'Site Manager reports this console is offline.',
                $code === 'forbidden' && str_contains((string) $response->json('message'), 'host not found') => 'Site Manager has no console with this ID. Use Discover and copy the full ID, including the part after the colon.',
                $code === 'forbidden' => 'Site Manager denied access: the key owner must be an owner of this console.',
                in_array($response->status(), [401, 403], true) && ! $this->isCloud() => "The console rejected the API key (HTTP {$response->status()}). Keys created at unifi.ui.com are Site Manager keys: either switch the connection to \"Site Manager cloud connector\", or create a local key under Network → Settings → Control Plane → Integrations.",
                in_array($response->status(), [401, 403], true) => "Site Manager rejected the API key (HTTP {$response->status()}).",
                $response->status() === 404 => 'The integration API was not found. Check the controller URL, console ID and API prefix.',
                default => "UniFi returned HTTP {$response->status()}.",
            }];
        }

        $version = $this->client()->get($this->path('/info'))->json('applicationVersion');

        return [
            'ok' => true,
            'message' => 'Connected to the official UniFi Network API'.($this->isCloud() ? ' through the Site Manager connector' : '').($version ? " (Network {$version})" : '').'.',
            'details' => ['sites' => $response->json('data', $response->json())],
        ];
    }

    /**
     * Consoles visible to a Site Manager key, or null when the key is rejected.
     *
     * @return list<array{id: string, name: string}>|null
     */
    public function consoles(): ?array
    {
        $response = $this->cloudClient()->get('/v1/hosts');
        if (! $response->successful()) {
            return null;
        }

        return collect($response->json('data', []))
            ->filter(fn (array $host) => ($host['type'] ?? '') === 'console')
            ->map(fn (array $host) => [
                'id' => (string) $host['id'],
                'name' => (string) ($host['reportedState']['name'] ?? $host['reportedState']['hostname'] ?? $host['id']),
            ])->values()->all();
    }

    public function discover(?string $siteId = null): array
    {
        try {
            $consoles = $this->isCloud() ? ($this->consoles() ?? []) : [];
            if ($this->isCloud() && blank($this->integration->configuration['console_id'] ?? null)) {
                return ['consoles' => $consoles, 'sites' => [], 'networks' => [], 'message' => $consoles === [] ? 'No consoles are visible to this Site Manager key.' : null];
            }

            $sites = $this->client()->get($this->path('/sites'));
            if (! $sites->successful()) {
                return ['consoles' => $consoles, 'sites' => [], 'networks' => [], 'message' => "Site discovery failed (HTTP {$sites->status()})."];
            }

            $siteList = collect($sites->json('data', []))->map(fn (array $site) => [
                'id' => (string) ($site['id'] ?? ''),
                'name' => (string) ($site['name'] ?? $site['internalReference'] ?? $site['id'] ?? ''),
            ])->filter(fn (array $site) => $site['id'] !== '')->values()->all();

            $siteId = $siteId ?: ($this->integration->configuration['site_id'] ?? null) ?: ($siteList[0]['id'] ?? null);
            if (! $siteId) {
                return ['consoles' => $consoles, 'sites' => $siteList, 'networks' => []];
            }

            $networks = $this->client()->get($this->path('/sites/'.rawurlencode($siteId).'/networks'), ['limit' => 200]);
            if (! $networks->successful()) {
                return ['consoles' => $consoles, 'sites' => $siteList, 'networks' => [], 'message' => 'This UniFi version does not list networks through the official API. Enter network IDs manually.'];
            }

            $networkList = collect($networks->json('data', []))->map(fn (array $network) => [
                'id' => (string) ($network['id'] ?? ''),
                'name' => (string) ($network['name'] ?? ''),
                'vlan' => isset($network['vlanId']) ? (int) $network['vlanId'] : null,
            ])->filter(fn (array $network) => $network['id'] !== '')->values()->all();

            return ['consoles' => $consoles, 'sites' => $siteList, 'networks' => $networkList];
        } catch (Throwable $exception) {
            report($exception);

            return ['sites' => [], 'networks' => [], 'message' => 'The UniFi controller could not be reached.'];
        }
    }

    /**
     * The UniFi web UI shows console IDs without the ":<number>" suffix that
     * the connector requires. Resolve a shortened ID to the full one.
     */
    public function resolveConsoleId(string $consoleId): string
    {
        $consoleId = trim($consoleId);
        if ($consoleId === '' || str_contains($consoleId, ':')) {
            return $consoleId;
        }

        $match = collect($this->consoles() ?? [])->first(fn (array $console) => strcasecmp(strtok($console['id'], ':'), $consoleId) === 0);

        return $match['id'] ?? $consoleId;
    }

    public function contentFilters(): array
    {
        return collect($this->rawContentFilters())->filter(fn ($filter) => is_array($filter) && isset($filter['_id']))->map(fn (array $filter) => [
            'id' => (string) $filter['_id'],
            'name' => (string) ($filter['name'] ?? $filter['_id']),
            'enabled' => (bool) ($filter['enabled'] ?? true),
            'allow' => array_values(array_map('strval', $filter['allow_list'] ?? [])),
            'block' => array_values(array_map('strval', $filter['block_list'] ?? [])),
            'network_ids' => array_values(array_map('strval', $filter['network_ids'] ?? [])),
            'categories' => array_values(array_map('strval', $filter['categories'] ?? [])),
            'safe_search' => array_values(array_map('strval', $filter['safe_search'] ?? [])),
            // ALWAYS, or a time-based mode such as CUSTOM: a filter that only applies at certain times.
            'schedule' => strtoupper((string) ($filter['schedule']['mode'] ?? 'ALWAYS')),
            // When it applies, in words ("Mon–Fri 08:30–15:10"). Pushes never write the schedule; UniFi keeps it.
            'schedule_label' => FilterSchedule::label(is_array($filter['schedule'] ?? null) ? $filter['schedule'] : null),
        ])->values()->all();
    }

    public function writeContentFilterLists(string $filterId, array $allow, array $block): void
    {
        $record = $this->rawContentFilter($filterId);
        $record['allow_list'] = array_values($allow);
        $record['block_list'] = array_values($block);

        $response = $this->client()->timeout(25)->put($this->contentFilterPath('/'.rawurlencode($filterId)), $record);
        $this->ensureSuccessful($response, 'content filter changes', writing: true);
        $this->noteFilterSaved();

        $saved = $this->rawContentFilter($filterId);
        $normalise = fn (array $values): array => collect($values)->map(fn ($value) => strtolower((string) $value))->unique()->sort()->values()->all();
        if ($normalise($saved['allow_list'] ?? []) !== $normalise($allow) || $normalise($saved['block_list'] ?? []) !== $normalise($block)) {
            throw new ProviderRequestFailed('UniFi accepted the change but the filter read back differently. Check the filter in UniFi before trying again.');
        }
    }

    public function attachNetworksToContentFilter(string $filterId, array $networkIds): void
    {
        $record = $this->rawContentFilter($filterId);
        $wanted = array_values(array_unique([...array_map('strval', $record['network_ids'] ?? []), ...array_map('strval', $networkIds)]));
        $record['network_ids'] = $wanted;

        $response = $this->client()->timeout(25)->put($this->contentFilterPath('/'.rawurlencode($filterId)), $record);
        $this->ensureSuccessful($response, 'the filter\'s networks', writing: true);
        $this->noteFilterSaved();

        $saved = array_map('strval', $this->rawContentFilter($filterId)['network_ids'] ?? []);
        if (array_diff($wanted, $saved) !== []) {
            throw new ProviderRequestFailed('UniFi accepted the change but the filter read back without every network. Check the filter in UniFi before trying again.');
        }
    }

    public function detachNetworksFromContentFilter(string $filterId, array $networkIds): void
    {
        $record = $this->rawContentFilter($filterId);
        $remove = array_map('strval', $networkIds);
        $record['network_ids'] = array_values(array_diff(array_map('strval', $record['network_ids'] ?? []), $remove));

        $response = $this->client()->timeout(25)->put($this->contentFilterPath('/'.rawurlencode($filterId)), $record);
        $this->ensureSuccessful($response, 'the filter\'s networks', writing: true);
        $this->noteFilterSaved();

        $saved = array_map('strval', $this->rawContentFilter($filterId)['network_ids'] ?? []);
        if (array_intersect($remove, $saved) !== []) {
            throw new ProviderRequestFailed('UniFi accepted the change but the network is still on the filter. Check the filter in UniFi before trying again.');
        }
    }

    public function createContentFilter(string $name, array $networkIds, array $categories): array
    {
        if ($categories === []) {
            throw new ProviderRequestFailed('UniFi needs at least one content category on a filter.');
        }

        // The Network application's own settings page creates filters with POST .../content-filtering/create.
        $response = $this->client()->timeout(25)->post($this->contentFilterPath('/create'), [
            'name' => $name, 'enabled' => true, 'network_ids' => array_values($networkIds), 'client_macs' => [],
            'allow_list' => [], 'block_list' => [], 'categories' => array_values($categories), 'safe_search' => [], 'schedule' => ['mode' => 'ALWAYS'],
        ]);
        $this->ensureSuccessful($response, 'the new content filter', writing: true);
        $this->noteFilterSaved();

        $id = (string) ($response->json('_id') ?? '');
        $created = collect($this->contentFilters())->firstWhere('id', $id);
        if ($id === '' || ! $created) {
            throw new ProviderRequestFailed('UniFi answered but the new content filter is not in its list. Check Content Filtering in UniFi before trying again.');
        }

        return $created;
    }

    public function availableCategories(): array
    {
        $response = $this->client()->timeout(20)->get($this->contentFilterPath('/categories'));
        $this->ensureSuccessful($response, 'content categories');

        return collect($response->json() ?? [])->filter(fn ($category) => is_string($category) && $category !== '')->values()->all();
    }

    public function writeContentFilterCategories(string $filterId, array $categories): void
    {
        $record = $this->rawContentFilter($filterId);
        $record['categories'] = array_values($categories);

        $response = $this->client()->timeout(25)->put($this->contentFilterPath('/'.rawurlencode($filterId)), $record);
        $this->ensureSuccessful($response, 'content categories', writing: true);
        $this->noteFilterSaved();

        $saved = collect($this->rawContentFilter($filterId)['categories'] ?? [])->sort()->values()->all();
        if ($saved !== collect($categories)->unique()->sort()->values()->all()) {
            throw new ProviderRequestFailed('UniFi accepted the category change but the filter read back differently. Check the filter in UniFi.');
        }
    }

    public function verifyContentFilterWrite(string $filterId): array
    {
        try {
            $record = $this->rawContentFilter($filterId);
            $response = $this->client()->timeout(25)->put($this->contentFilterPath('/'.rawurlencode($filterId)), $record);
            $this->ensureSuccessful($response, 'content filter changes', writing: true);
            $this->noteFilterSaved();
            $saved = $this->rawContentFilter($filterId);
        } catch (ProviderRequestFailed $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }

        $same = ($saved['allow_list'] ?? []) === ($record['allow_list'] ?? []) && ($saved['block_list'] ?? []) === ($record['block_list'] ?? [])
            && ($saved['network_ids'] ?? []) === ($record['network_ids'] ?? []);

        return $same
            ? ['ok' => true, 'message' => "UniFi accepted an unchanged save of {$record['name']}: this key can update content filters."]
            : ['ok' => false, 'message' => 'UniFi accepted the save but the filter read back differently. Check it in UniFi.'];
    }

    /** @return list<array<string, mixed>> */
    private function rawContentFilters(): array
    {
        $response = $this->client()->timeout(20)->get($this->contentFilterPath());
        $this->ensureSuccessful($response, 'content filters');

        $filters = $response->json();
        if (! is_array($filters) || ! array_is_list($filters)) {
            throw new ProviderRequestFailed('UniFi returned content filters in an unexpected format. Paste the lists instead.');
        }

        return $filters;
    }

    /** @return array<string, mixed> */
    private function rawContentFilter(string $filterId): array
    {
        $record = collect($this->rawContentFilters())->first(fn ($filter) => is_array($filter) && ($filter['_id'] ?? null) === $filterId);
        if (! $record) {
            throw new ProviderRequestFailed('That content filter no longer exists in UniFi. Re-link the policy under Networks & filters.');
        }

        return $record;
    }

    private function contentFilterPath(string $suffix = ''): string
    {
        return $this->networkPath('/v2/api/site/'.rawurlencode($this->siteReference()).'/content-filtering'.$suffix);
    }

    /** Cached for 5 minutes: listing groups returns every address and takes several seconds through the cloud. */
    public function ipGroups(): array
    {
        return Cache::remember(self::FIREWALL_CACHE.'groups.v2', 300, fn () => $this->readIpGroups());
    }

    /** @return list<array{id: string, name: string, family: string, count: int}> */
    private function readIpGroups(): array
    {
        return collect($this->paginated('/traffic-matching-lists'))
            ->filter(fn ($group) => in_array($group['type'] ?? '', ['IPV4_ADDRESSES', 'IPV6_ADDRESSES'], true))
            ->map(fn (array $group) => [
                'id' => (string) $group['id'],
                'name' => (string) ($group['name'] ?? $group['id']),
                'family' => $group['type'] === 'IPV6_ADDRESSES' ? 'ipv6' : 'ipv4',
                'count' => count($group['items'] ?? []),
                'items' => collect($group['items'] ?? [])->map(fn ($item) => (string) (is_array($item) ? ($item['value'] ?? '') : $item))->filter()->values()->all(),
            ])->values()->all();
    }

    public function writeIpGroup(string $id, string $name, string $family, array $entries): void
    {
        $path = $this->path('/sites/'.rawurlencode($this->siteId()).'/traffic-matching-lists/'.rawurlencode($id));
        $response = $this->client()->timeout(60)->put($path, $this->ipGroupBody($name, $family, $entries));
        $this->ensureSuccessful($response, 'IP group changes', writing: true);
        $this->forgetFirewall();

        $saved = $this->client()->timeout(30)->get($path);
        $this->ensureSuccessful($saved, 'the IP group');
        $savedValues = collect($saved->json('items', []))->pluck('value')->map(fn ($value) => (string) $value)->sort()->values()->all();
        if ($savedValues !== collect($entries)->sort()->values()->all()) {
            throw new ProviderRequestFailed('UniFi accepted the IP group change but it read back differently ('.count($savedValues).' of '.count($entries).' entries). The controller may limit the group size.');
        }
    }

    public function createIpGroup(string $name, string $family, array $entries): string
    {
        $response = $this->client()->timeout(60)->post($this->path('/sites/'.rawurlencode($this->siteId()).'/traffic-matching-lists'), $this->ipGroupBody($name, $family, $entries));
        $this->ensureSuccessful($response, 'a new IP group', writing: true);
        $this->forgetFirewall();
        $id = (string) ($response->json('id') ?? $response->json('data.id') ?? '');
        if ($id === '') {
            throw new ProviderRequestFailed('UniFi created the IP group but did not return its ID. Refresh and link it by name.');
        }

        return $id;
    }

    public function deleteIpGroup(string $id): void
    {
        $response = $this->client()->timeout(30)->delete($this->path('/sites/'.rawurlencode($this->siteId()).'/traffic-matching-lists/'.rawurlencode($id)));
        if ($response->status() !== 404) {
            $this->ensureSuccessful($response, 'deleting the IP group', writing: true);
        }
        $this->forgetFirewall();
    }

    public function firewallZones(): array
    {
        return Cache::remember(self::FIREWALL_CACHE.'zones.v4', 3600, function (): array {
            $networks = collect(rescue(fn () => $this->paginated('/networks'), [], false))
                ->filter(fn ($network) => is_array($network) && isset($network['id']))->keyBy(fn (array $network) => (string) $network['id']);
            $names = $networks->map(fn (array $network) => (string) ($network['name'] ?? $network['id']));

            return collect($this->paginated('/firewall/zones'))
                ->filter(fn ($zone) => is_array($zone) && isset($zone['id']))
                ->map(fn (array $zone) => [
                    'id' => (string) $zone['id'], 'name' => (string) ($zone['name'] ?? $zone['id']),
                    'networks' => $this->zoneNetworkNames((array) ($zone['networkIds'] ?? []), $names->all()),
                    'network_ids' => array_values(array_map('strval', (array) ($zone['networkIds'] ?? []))),
                    // The Integration API's own network IDs differ from the Network application's, so networks are
                    // matched across the two by VLAN (then name).
                    'members' => collect((array) ($zone['networkIds'] ?? []))->map(fn ($id) => $networks->get((string) $id))->filter()
                        ->map(fn (array $network) => ['id' => (string) $network['id'], 'name' => (string) ($network['name'] ?? ''), 'vlan' => isset($network['vlanId']) ? (int) $network['vlanId'] : null])->values()->all(),
                ])->values()->all();
        });
    }

    /**
     * @param  list<mixed>  $ids
     * @param  array<string, string>  $names
     * @return list<string>
     */
    private function zoneNetworkNames(array $ids, array $names): array
    {
        $known = array_values(array_filter(array_map(fn ($id) => $names[(string) $id] ?? null, $ids)));
        $other = count($ids) - count($known);

        // VPN servers and WAN links are zone members the networks endpoint does not list.
        return $other > 0 ? [...$known, $other.' other network'.($other === 1 ? '' : 's').' (VPN or WAN)'] : $known;
    }

    public function firewallRules(): array
    {
        return collect(Cache::remember(self::FIREWALL_CACHE.'policies', 300, fn () => $this->paginated('/firewall/policies')))
            ->filter(fn ($policy) => is_array($policy) && isset($policy['id']))
            ->map(fn (array $policy) => [
                'id' => (string) $policy['id'], 'name' => (string) ($policy['name'] ?? $policy['id']),
                'action' => (string) ($policy['action']['type'] ?? ''), 'enabled' => (bool) ($policy['enabled'] ?? false),
                'index' => (int) ($policy['index'] ?? PHP_INT_MAX),
                'source' => (string) ($policy['source']['zoneId'] ?? ''), 'destination' => (string) ($policy['destination']['zoneId'] ?? ''),
                'user' => ($policy['metadata']['origin'] ?? 'USER_DEFINED') === 'USER_DEFINED',
                'match' => $this->destinationMatch((array) ($policy['destination'] ?? [])),
                'source_match' => $this->sourceMatch((array) ($policy['source'] ?? [])),
                'protocol' => $this->protocolOf((array) ($policy['ipProtocolScope'] ?? [])),
                'ip_version' => strtoupper((string) ($policy['ipProtocolScope']['ipVersion'] ?? 'IPV4_AND_IPV6')),
                'states' => isset($policy['connectionStateFilter']) ? array_values(array_map('strval', (array) $policy['connectionStateFilter'])) : null,
            ])->values()->all();
    }

    /**
     * What a rule's destination matches, from its traffic filter: any
     * address, an IP group (traffic matching list), listed addresses, or
     * something an address alone cannot be checked against.
     *
     * @param  array<string, mixed>  $destination
     * @return array{kind: string, group?: string, addresses?: list<string>, opposite?: bool, ports?: list<array{0: int, 1: int}>|null, label?: string}
     */
    private function sourceMatch(array $source): array
    {
        $filter = (array) ($source['trafficFilter'] ?? []);

        return match (true) {
            $filter === [] || ($filter['type'] ?? '') === 'PORT' => ['kind' => 'any'],
            ($filter['type'] ?? '') === 'NETWORK' => ['kind' => 'networks', 'networks' => array_values(array_map('strval', (array) ($filter['networkFilter']['networkIds'] ?? []))), 'opposite' => (bool) ($filter['networkFilter']['matchOpposite'] ?? false)],
            ($filter['type'] ?? '') === 'MAC_ADDRESS' => ['kind' => 'devices', 'label' => count((array) ($filter['macAddressFilter']['macAddresses'] ?? [])).' device(s) by MAC address'],
            default => (function () use ($filter): array {
                $destination = $this->destinationMatch(['trafficFilter' => $filter]);

                return $destination + ['label' => match ($destination['kind']) {
                    'addresses' => implode(', ', array_slice($destination['addresses'] ?? [], 0, 3)),
                    'group' => 'an IP list',
                    default => $destination['label'] ?? 'some sources',
                }];
            })(),
        };
    }

    /** TCP, UDP, both, or another protocol (ICMP…); "any" when the rule names none. */
    private function protocolOf(array $scope): string
    {
        $filter = (array) ($scope['protocolFilter'] ?? []);
        if ($filter === []) {
            return 'any';
        }
        $name = strtoupper((string) ($filter['protocol']['name'] ?? $filter['preset']['name'] ?? ''));
        $protocol = match ($name) {
            'TCP' => 'tcp', 'UDP' => 'udp', 'TCP_UDP' => 'tcp_udp', default => strtolower($name) ?: 'other',
        };

        return ! empty($filter['matchOpposite']) ? "not_{$protocol}" : $protocol;
    }

    private function destinationMatch(array $destination): array
    {
        $filter = (array) ($destination['trafficFilter'] ?? []);
        if ($filter === []) {
            return ['kind' => 'any', 'ports' => null];
        }
        $ports = null;
        if (isset($filter['portFilter']) && is_array($filter['portFilter'])) {
            $portFilter = $filter['portFilter'];
            $ports = ($portFilter['type'] ?? '') === 'TRAFFIC_MATCHING_LIST' ? [] : collect((array) ($portFilter['items'] ?? []))->map(fn ($item) => is_array($item)
                ? (isset($item['start']) ? [(int) $item['start'], (int) ($item['stop'] ?? $item['end'] ?? $item['start'])] : [(int) ($item['value'] ?? 0), (int) ($item['value'] ?? 0)])
                : [(int) $item, (int) $item])->values()->all();
        }
        if (($filter['type'] ?? '') === 'NETWORK') {
            return ['kind' => 'networks', 'networks' => array_values(array_map('strval', (array) ($filter['networkFilter']['networkIds'] ?? []))), 'ports' => $ports];
        }
        $address = $filter['ipAddressFilter'] ?? null;
        if (! is_array($address)) {
            return in_array($filter['type'] ?? '', ['PORT'], true) ? ['kind' => 'any', 'ports' => $ports] : ['kind' => 'unknown', 'label' => strtolower(str_replace('_', ' ', (string) ($filter['type'] ?? 'other')))];
        }
        $opposite = (bool) ($address['matchOpposite'] ?? false);
        if (($address['type'] ?? '') === 'TRAFFIC_MATCHING_LIST') {
            return ['kind' => 'group', 'group' => (string) ($address['trafficMatchingListId'] ?? ''), 'opposite' => $opposite, 'ports' => $ports];
        }
        $items = collect((array) ($address['items'] ?? $address['addresses'] ?? []))->map(fn ($item) => (string) (is_array($item) ? ($item['value'] ?? '') : $item))->filter()->values()->all();

        return $items === [] ? ['kind' => 'unknown', 'label' => 'addresses'] : ['kind' => 'addresses', 'addresses' => $items, 'opposite' => $opposite, 'ports' => $ports];
    }

    public function placeRulesFirst(string $sourceZoneId, string $destinationZoneId, array $ruleIds): void
    {
        FirewallRuleMode::guard($this->integration, 'reorder firewall rules');
        $path = $this->path('/sites/'.rawurlencode($this->siteId()).'/firewall/policies/ordering');
        $query = ['sourceFirewallZoneId' => $sourceZoneId, 'destinationFirewallZoneId' => $destinationZoneId];
        $response = $this->client()->timeout(30)->get($path, $query);
        $this->ensureSuccessful($response, 'the firewall rule order');
        $before = array_values(array_map('strval', (array) $response->json('orderedFirewallPolicyIds.beforeSystemDefined', [])));
        $after = array_values(array_map('strval', (array) $response->json('orderedFirewallPolicyIds.afterSystemDefined', [])));
        $ours = array_values(array_intersect($ruleIds, [...$before, ...$after]));
        $wanted = [...$ours, ...array_values(array_diff($before, $ours))];
        if (array_slice($before, 0, count($ours)) === $ours && array_intersect($after, $ours) === []) {
            return;
        }

        $saved = $this->client()->timeout(30)->withQueryParameters($query)->put($path, ['orderedFirewallPolicyIds' => [
            'beforeSystemDefined' => $wanted, 'afterSystemDefined' => array_values(array_diff($after, $ours)),
        ]]);
        $this->ensureSuccessful($saved, 'the firewall rule order', writing: true);
        $this->forgetFirewall();
    }

    public function placeRulesBefore(string $sourceZoneId, string $destinationZoneId, array $ruleIds, string $beforeRuleId): void
    {
        FirewallRuleMode::guard($this->integration, 'reorder firewall rules');
        $path = $this->path('/sites/'.rawurlencode($this->siteId()).'/firewall/policies/ordering');
        $query = ['sourceFirewallZoneId' => $sourceZoneId, 'destinationFirewallZoneId' => $destinationZoneId];
        $response = $this->client()->timeout(30)->get($path, $query);
        $this->ensureSuccessful($response, 'the firewall rule order');
        $before = array_values(array_map('strval', (array) $response->json('orderedFirewallPolicyIds.beforeSystemDefined', [])));
        $after = array_values(array_map('strval', (array) $response->json('orderedFirewallPolicyIds.afterSystemDefined', [])));
        $ours = array_values(array_intersect($ruleIds, [...$before, ...$after]));
        $rest = array_values(array_diff($before, $ours));
        $at = array_search($beforeRuleId, $rest, true);
        $wanted = $at === false ? [...$ours, ...$rest] : [...array_slice($rest, 0, $at), ...$ours, ...array_slice($rest, $at)];
        if ($wanted === $before && array_intersect($after, $ours) === []) {
            return;
        }

        $saved = $this->client()->timeout(30)->withQueryParameters($query)->put($path, ['orderedFirewallPolicyIds' => [
            'beforeSystemDefined' => $wanted, 'afterSystemDefined' => array_values(array_diff($after, $ours)),
        ]]);
        $this->ensureSuccessful($saved, 'the firewall rule order', writing: true);
        $this->forgetFirewall();
    }

    public function createBlockRule(string $name, string $groupId, string $family, string $sourceZoneId, string $destinationZoneId, bool $groupIsSource = false, string $action = 'BLOCK', array $sourceNetworkIds = []): string
    {
        FirewallRuleMode::guard($this->integration, 'create firewall rules');
        $filter = ['type' => 'IP_ADDRESS', 'ipAddressFilter' => ['type' => 'TRAFFIC_MATCHING_LIST', 'matchOpposite' => false, 'trafficMatchingListId' => $groupId]];
        $allow = $action === 'ALLOW';
        $source = $groupIsSource ? ['trafficFilter' => $filter]
            : ($sourceNetworkIds !== [] ? ['trafficFilter' => ['type' => 'NETWORK', 'networkFilter' => ['networkIds' => array_values($sourceNetworkIds), 'matchOpposite' => false]]] : []);
        $response = $this->client()->timeout(30)->post($this->path('/sites/'.rawurlencode($this->siteId()).'/firewall/policies'), [
            'enabled' => true, 'name' => $name, 'action' => ['type' => $allow ? 'ALLOW' : 'BLOCK'] + ($allow ? ['allowReturnTraffic' => true] : []),
            'source' => ['zoneId' => $sourceZoneId] + $source,
            'destination' => ['zoneId' => $destinationZoneId] + ($groupIsSource ? [] : ['trafficFilter' => $filter]),
            'ipProtocolScope' => ['ipVersion' => $family === 'ipv6' ? 'IPV6' : 'IPV4'],
            // An Allow rule never lets invalid-state traffic through ahead of UniFi's own Block Invalid Traffic.
            ...($allow ? ['connectionStateFilter' => ['NEW', 'RELATED', 'ESTABLISHED']] : []),
            'loggingEnabled' => false,
        ]);
        $this->ensureSuccessful($response, 'a firewall rule', writing: true);
        $this->forgetFirewall();
        $id = (string) ($response->json('id') ?? '');
        if ($id === '') {
            throw new ProviderRequestFailed('UniFi created the firewall rule but did not return its ID. Check Firewall rules in UniFi.');
        }

        return $id;
    }

    public function deleteFirewallRule(string $id): void
    {
        FirewallRuleMode::guard($this->integration, 'delete firewall rules');
        $response = $this->client()->timeout(30)->delete($this->path('/sites/'.rawurlencode($this->siteId()).'/firewall/policies/'.rawurlencode($id)));
        if ($response->status() !== 404) {
            $this->ensureSuccessful($response, 'deleting the firewall rule', writing: true);
        }
        $this->forgetFirewall();
    }

    public function firewallRulesUsing(string $id): array
    {
        // One read of every rule serves all groups (and firewallRules()) for 5 minutes.
        $policies = Cache::remember(self::FIREWALL_CACHE.'policies', 300, fn () => $this->paginated('/firewall/policies'));

        return collect($policies)
            ->filter(fn ($policy) => is_array($policy) && str_contains((string) json_encode($policy), $id))
            ->map(fn (array $policy) => [
                'name' => (string) ($policy['name'] ?? $policy['id'] ?? ''),
                'action' => (string) ($policy['action']['type'] ?? ''),
                'enabled' => (bool) ($policy['enabled'] ?? false),
            ])->values()->all();
    }

    /**
     * @param  list<string>  $entries
     * @return array<string, mixed>
     */
    private function ipGroupBody(string $name, string $family, array $entries): array
    {
        return [
            'type' => $family === 'ipv6' ? 'IPV6_ADDRESSES' : 'IPV4_ADDRESSES',
            'name' => $name,
            'items' => array_map(fn (string $value) => ['type' => str_contains($value, '/') ? 'SUBNET' : 'IP_ADDRESS', 'value' => $value], array_values($entries)),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function paginated(string $endpoint): array
    {
        $items = [];
        $offset = 0;
        do {
            $response = $this->client()->timeout(30)->get($this->path('/sites/'.rawurlencode($this->siteId()).$endpoint), ['limit' => 200, 'offset' => $offset]);
            $this->ensureSuccessful($response, ltrim($endpoint, '/'));
            $page = $response->json('data', []);
            $items = [...$items, ...$page];
            $offset += 200;
        } while (count($page) === 200 && $offset < 10000);

        return $items;
    }

    private ?string $siteIdentifier = null;

    /**
     * The integration API's site ID. A configured value is used when it names
     * a site on the console (by id, internal reference such as "default", or
     * name); anything else (for example the console ID pasted by mistake)
     * falls back to the first site.
     */
    private function siteId(): string
    {
        if ($this->siteIdentifier !== null) {
            return $this->siteIdentifier;
        }
        $response = $this->client()->get($this->path('/sites'));
        $this->ensureSuccessful($response, 'sites');
        $sites = collect($response->json('data', []));
        if ($sites->isEmpty()) {
            throw new ProviderRequestFailed('The controller reported no sites.');
        }

        $configured = trim((string) ($this->integration->configuration['site_id'] ?? ''));
        $site = $configured === '' ? null : $sites->first(fn ($site) => in_array($configured, [(string) ($site['id'] ?? ''), (string) ($site['internalReference'] ?? ''), (string) ($site['name'] ?? '')], true));

        return $this->siteIdentifier = (string) (($site ?? $sites->first())['id']);
    }

    public function networks(): array
    {
        $response = $this->client()->timeout(20)->get($this->networkPath('/api/s/'.rawurlencode($this->siteReference()).'/rest/networkconf'));
        $this->ensureSuccessful($response, 'networks');

        return collect($response->json('data', []))
            ->filter(fn ($network) => is_array($network) && isset($network['_id']) && in_array($network['purpose'] ?? 'corporate', self::FILTERABLE_PURPOSES, true))
            ->map(fn (array $network) => [
                'id' => (string) $network['_id'],
                'name' => (string) ($network['name'] ?? $network['_id']),
                'vlan' => isset($network['vlan']) && $network['vlan'] !== '' ? (int) $network['vlan'] : null,
                'purpose' => isset($network['purpose']) ? (string) $network['purpose'] : null,
                'subnet' => filled($network['ip_subnet'] ?? null) ? (string) $network['ip_subnet'] : null,
                'dhcp_start' => ($network['dhcpd_enabled'] ?? false) && filled($network['dhcpd_start'] ?? null) ? (string) $network['dhcpd_start'] : null,
                'dhcp_stop' => ($network['dhcpd_enabled'] ?? false) && filled($network['dhcpd_stop'] ?? null) ? (string) $network['dhcpd_stop'] : null,
                'dhcp_mode' => ($network['dhcp_relay_enabled'] ?? false) ? 'relay' : (($network['dhcpd_enabled'] ?? false) ? 'unifi' : 'none'),
            ])
            ->sortBy(fn (array $network) => [$network['vlan'] ?? 0, $network['name']])
            ->values()->all();
    }

    public function reservations(): array
    {
        $response = $this->client()->timeout(20)->get($this->networkPath('/api/s/'.rawurlencode($this->siteReference()).'/rest/user'));
        $this->ensureSuccessful($response, 'client reservations');

        return collect($response->json('data', []))
            ->filter(fn ($client) => is_array($client) && ($client['use_fixedip'] ?? false) && filled($client['fixed_ip'] ?? null) && filled($client['mac'] ?? null))
            ->mapWithKeys(fn (array $client) => [strtolower((string) $client['mac']) => [
                'ip' => (string) $client['fixed_ip'], 'network_id' => isset($client['network_id']) ? (string) $client['network_id'] : null, 'name' => $client['name'] ?? null,
            ]])->all();
    }

    public function reserveAddress(string $mac, string $name, string $networkId, string $ip): void
    {
        $base = '/api/s/'.rawurlencode($this->siteReference()).'/rest/user';
        $mac = strtolower($mac);
        $existing = collect($this->client()->timeout(20)->get($this->networkPath($base))->json('data', []))->first(fn ($client) => strtolower((string) ($client['mac'] ?? '')) === $mac);
        $body = ['mac' => $mac, 'name' => $name, 'use_fixedip' => true, 'fixed_ip' => $ip, 'network_id' => $networkId, 'note' => 'Filterdesk probe'];

        $response = $existing && isset($existing['_id'])
            ? $this->client()->timeout(20)->put($this->networkPath($base.'/'.rawurlencode((string) $existing['_id'])), $body)
            : $this->client()->timeout(20)->post($this->networkPath($base), $body);
        $this->ensureSuccessful($response, 'the address reservation', writing: true);

        $saved = $this->reservations()[$mac] ?? null;
        if (($saved['ip'] ?? null) !== $ip) {
            throw new ProviderRequestFailed('UniFi answered but the reservation did not read back. Check Client Devices in UniFi.');
        }
    }

    /**
     * The short site name ("default") the Network application's own API
     * uses, looked up from the configured site ID when one is set.
     */
    private function siteReference(): string
    {
        if ($this->siteReference !== null) {
            return $this->siteReference;
        }

        $configured = $this->integration->configuration['site_reference'] ?? null;
        if (filled($configured)) {
            return $this->siteReference = (string) $configured;
        }

        $siteId = $this->integration->configuration['site_id'] ?? null;
        if (filled($siteId)) {
            $site = collect(rescue(fn () => $this->client()->get($this->path('/sites'))->json('data', []), [], false))
                ->first(fn ($site) => in_array((string) $siteId, [(string) ($site['id'] ?? ''), (string) ($site['internalReference'] ?? ''), (string) ($site['name'] ?? '')], true));
            if (filled($site['internalReference'] ?? null)) {
                return $this->siteReference = (string) $site['internalReference'];
            }
        }

        return $this->siteReference = 'default';
    }

    private function ensureSuccessful(Response $response, string $what, bool $writing = false): void
    {
        if ($response->successful()) {
            return;
        }

        $code = (string) $response->json('code', '');
        throw new ProviderRequestFailed(match (true) {
            $response->status() === self::UNREACHABLE => 'The UniFi controller could not be reached ('.str((string) $response->json('message'))->before(' (see')->after(': ')->limit(120).'). Nothing was changed; it is retried automatically.',
            $code === 'device_offline' => 'Site Manager reports this console is offline.',
            $writing && in_array($response->status(), [401, 403], true) => "UniFi refused to save {$what} (HTTP {$response->status()}). The key's owner needs Network admin rights, and some Site Manager keys are read-only.",
            $writing && in_array($response->status(), [404, 405], true) => "This UniFi Network version does not accept {$what} at the expected address (HTTP {$response->status()}). Apply changes by hand.",
            str_contains((string) $response->json('message'), 'host not found') => 'Site Manager has no console with this ID. Use the full console ID from Discover.',
            in_array($response->status(), [401, 403], true) => "UniFi refused to list {$what} (HTTP {$response->status()}). The key needs Network admin access.",
            $response->status() === 404 => "This UniFi Network version does not expose {$what} at the expected address. Paste the lists instead.",
            default => "UniFi returned HTTP {$response->status()} when listing {$what}.",
        });
    }

    public function apply(WebsiteRequest $request): array
    {
        throw new UnsupportedProviderOperation(
            'This UniFi Network version does not expose a supported domain allowlist operation. Copy the change from the policy page into UniFi, then mark the request applied.'
        );
    }

    public function revoke(WebsiteRequest $request): void
    {
        if (! isset($request->provider_state['resource_id'])) {
            return;
        }

        throw new UnsupportedProviderOperation('Automatic removal is unavailable for this provider resource.');
    }

    private function client(): PendingRequest
    {
        if ($this->isCloud()) {
            return $this->cloudClient();
        }

        $verify = (bool) ($this->integration->configuration['verify_tls'] ?? true);

        return $this->keyed(Http::baseUrl(rtrim((string) $this->integration->configuration['controller_url'], '/')))
            ->withOptions(['verify' => $verify]);
    }

    /** TLS is always verified for Ubiquiti's cloud endpoint. */
    private function cloudClient(): PendingRequest
    {
        return $this->keyed(Http::baseUrl(self::CLOUD_BASE_URL))->timeout(15);
    }

    private function keyed(PendingRequest $request): PendingRequest
    {
        // Saving a content filter reloads the gateway's DNS for up to ~30 s. When the app
        // resolves names through that gateway, the next call can fail to resolve or
        // connect, so connection errors (never HTTP error responses) are retried.
        return $request->acceptJson()
            ->withHeader('X-API-Key', (string) (($this->integration->credentials ?? [])['api_key'] ?? ''))
            ->connectTimeout(3)
            ->timeout(8)
            // An unreachable controller (DNS failure, refused or timed-out connection) becomes an HTTP 599
            // response, so every caller gets a ProviderRequestFailed from ensureSuccessful() instead of an
            // uncaught exception, and it is retried like any connection error.
            ->withMiddleware(self::unreachableAsResponse())
            ->retry(Cache::get(self::DNS_RELOAD_KEY, 0) > time() ? self::RETRY_DELAYS_MS : [1000],
                when: fn (Throwable $exception) => $exception instanceof ConnectionException || ($exception instanceof RequestException && $exception->response->status() === self::UNREACHABLE),
                throw: false);
    }

    /** Status used for "the controller could not be reached". */
    public const UNREACHABLE = 599;

    private static function unreachableAsResponse(): callable
    {
        return fn (callable $handler) => fn ($request, array $options) => $handler($request, $options)->then(null, function ($reason) {
            if ($reason instanceof ConnectException) {
                return new Psr7Response(self::UNREACHABLE, ['Content-Type' => 'application/json'], (string) json_encode(['message' => $reason->getMessage()]));
            }

            return Create::rejectionFor($reason);
        });
    }

    /** Paths under the Network application itself (/proxy/network). */
    private function networkPath(string $endpoint): string
    {
        $prefix = rtrim((string) ($this->integration->configuration['api_prefix'] ?? '/proxy/network/integration/v1'), '/');

        return $this->wrap((string) preg_replace('#/integration/v\d+$#', '', $prefix).$endpoint);
    }

    private function wrap(string $path): string
    {
        if (! $this->isCloud()) {
            return $path;
        }

        $console = rawurlencode((string) ($this->integration->configuration['console_id'] ?? ''));

        return "/v1/connector/consoles/{$console}{$path}";
    }

    private function path(string $endpoint): string
    {
        return $this->wrap(rtrim((string) ($this->integration->configuration['api_prefix'] ?? '/proxy/network/integration/v1'), '/').$endpoint);
    }
}
