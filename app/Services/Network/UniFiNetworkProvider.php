<?php

namespace App\Services\Network;

use App\Models\IntegrationConfig;
use App\Models\WebsiteRequest;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
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

    private ?string $siteReference = null;

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
        ])->values()->all();
    }

    public function writeContentFilterLists(string $filterId, array $allow, array $block): void
    {
        $record = $this->rawContentFilter($filterId);
        $record['allow_list'] = array_values($allow);
        $record['block_list'] = array_values($block);

        $response = $this->client()->timeout(25)->put($this->contentFilterPath('/'.rawurlencode($filterId)), $record);
        $this->ensureSuccessful($response, 'content filter changes', writing: true);

        $saved = $this->rawContentFilter($filterId);
        $normalise = fn (array $values): array => collect($values)->map(fn ($value) => strtolower((string) $value))->unique()->sort()->values()->all();
        if ($normalise($saved['allow_list'] ?? []) !== $normalise($allow) || $normalise($saved['block_list'] ?? []) !== $normalise($block)) {
            throw new ProviderRequestFailed('UniFi accepted the change but the filter read back differently. Check the filter in UniFi before trying again.');
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

    public function ipGroups(): array
    {
        return collect($this->paginated('/traffic-matching-lists'))
            ->filter(fn ($group) => in_array($group['type'] ?? '', ['IPV4_ADDRESSES', 'IPV6_ADDRESSES'], true))
            ->map(fn (array $group) => [
                'id' => (string) $group['id'],
                'name' => (string) ($group['name'] ?? $group['id']),
                'family' => $group['type'] === 'IPV6_ADDRESSES' ? 'ipv6' : 'ipv4',
                'count' => count($group['items'] ?? []),
            ])->values()->all();
    }

    public function writeIpGroup(string $id, string $name, string $family, array $entries): void
    {
        $path = $this->path('/sites/'.rawurlencode($this->siteId()).'/traffic-matching-lists/'.rawurlencode($id));
        $response = $this->client()->timeout(60)->put($path, $this->ipGroupBody($name, $family, $entries));
        $this->ensureSuccessful($response, 'IP group changes', writing: true);

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
        $id = (string) ($response->json('id') ?? $response->json('data.id') ?? '');
        if ($id === '') {
            throw new ProviderRequestFailed('UniFi created the IP group but did not return its ID. Refresh and link it by name.');
        }

        return $id;
    }

    public function firewallRulesUsing(string $id): array
    {
        return collect($this->paginated('/firewall/policies'))
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

    /** The integration API's site ID: as configured, or the first site. */
    private function siteId(): string
    {
        if ($this->siteIdentifier !== null) {
            return $this->siteIdentifier;
        }
        $configured = $this->integration->configuration['site_id'] ?? null;
        if (filled($configured)) {
            return $this->siteIdentifier = (string) $configured;
        }
        $response = $this->client()->get($this->path('/sites'));
        $this->ensureSuccessful($response, 'sites');
        $first = $response->json('data.0.id');
        if (! $first) {
            throw new ProviderRequestFailed('The controller reported no sites.');
        }

        return $this->siteIdentifier = (string) $first;
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
        $body = ['mac' => $mac, 'name' => $name, 'use_fixedip' => true, 'fixed_ip' => $ip, 'network_id' => $networkId, 'note' => 'IT Requests probe'];

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
            $site = collect($this->client()->get($this->path('/sites'))->json('data', []))->firstWhere('id', $siteId);
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
            'This UniFi Network version does not expose a supported domain allowlist operation. Copy the change from Policy lists into UniFi, then mark the request applied.'
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
        return $request->acceptJson()
            ->withHeader('X-API-Key', (string) (($this->integration->credentials ?? [])['api_key'] ?? ''))
            ->connectTimeout(3)
            ->timeout(8);
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
