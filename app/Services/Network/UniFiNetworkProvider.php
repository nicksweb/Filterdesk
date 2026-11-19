<?php

namespace App\Services\Network;

use App\Models\IntegrationConfig;
use App\Models\WebsiteRequest;
use Illuminate\Http\Client\PendingRequest;
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
 * Discovery is read-only. Content-filter domain lists are not exposed by the
 * official API (checked against Network 11.0), so approvals are reconciled
 * through Policy lists rather than undocumented controller endpoints.
 */
class UniFiNetworkProvider implements NetworkProvider
{
    public function __construct(private readonly IntegrationConfig $integration) {}

    public function capabilities(): array
    {
        return [
            'site_discovery' => true,
            'network_discovery' => true,
            'firewall_policies' => true,
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

    private function path(string $endpoint): string
    {
        $prefix = rtrim((string) ($this->integration->configuration['api_prefix'] ?? '/proxy/network/integration/v1'), '/');

        if ($this->isCloud()) {
            $console = rawurlencode((string) ($this->integration->configuration['console_id'] ?? ''));

            return "/v1/connector/consoles/{$console}{$prefix}{$endpoint}";
        }

        return $prefix.$endpoint;
    }
}
