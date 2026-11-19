<?php

namespace App\Services\Network;

use App\Models\IntegrationConfig;
use App\Models\WebsiteRequest;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * UniFi Network via the official integration API (X-API-Key). Discovery is
 * read-only. Content-filter domain lists are not exposed by the official API,
 * so approvals are reconciled through the Policy lists workflow rather than
 * undocumented controller endpoints.
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

    public function test(): array
    {
        $response = $this->client()->get($this->path('/sites'));

        if (! $response->successful()) {
            return ['ok' => false, 'message' => match ($response->status()) {
                401, 403 => "UniFi rejected the API key (HTTP {$response->status()}). Create a new key under Control Plane → Integrations.",
                404 => 'The integration API was not found. Check the controller URL and API prefix.',
                default => "UniFi returned HTTP {$response->status()}.",
            }];
        }

        return ['ok' => true, 'message' => 'Connected to the official UniFi Network API.', 'details' => ['sites' => $response->json('data', $response->json())]];
    }

    public function discover(?string $siteId = null): array
    {
        try {
            $sites = $this->client()->get($this->path('/sites'));
            if (! $sites->successful()) {
                return ['sites' => [], 'networks' => [], 'message' => "Site discovery failed (HTTP {$sites->status()})."];
            }

            $siteList = collect($sites->json('data', []))->map(fn (array $site) => [
                'id' => (string) ($site['id'] ?? ''),
                'name' => (string) ($site['name'] ?? $site['internalReference'] ?? $site['id'] ?? ''),
            ])->filter(fn (array $site) => $site['id'] !== '')->values()->all();

            $siteId = $siteId ?: ($this->integration->configuration['site_id'] ?? null) ?: ($siteList[0]['id'] ?? null);
            if (! $siteId) {
                return ['sites' => $siteList, 'networks' => []];
            }

            $networks = $this->client()->get($this->path('/sites/'.rawurlencode($siteId).'/networks'), ['limit' => 200]);
            if (! $networks->successful()) {
                return ['sites' => $siteList, 'networks' => [], 'message' => 'This UniFi version does not list networks through the official API. Enter network IDs manually.'];
            }

            $networkList = collect($networks->json('data', []))->map(fn (array $network) => [
                'id' => (string) ($network['id'] ?? ''),
                'name' => (string) ($network['name'] ?? ''),
                'vlan' => isset($network['vlanId']) ? (int) $network['vlanId'] : null,
            ])->filter(fn (array $network) => $network['id'] !== '')->values()->all();

            return ['sites' => $siteList, 'networks' => $networkList];
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
        $credentials = $this->integration->credentials ?? [];
        $verify = (bool) ($this->integration->configuration['verify_tls'] ?? true);

        return Http::baseUrl(rtrim((string) $this->integration->configuration['controller_url'], '/'))
            ->acceptJson()
            ->withHeader('X-API-Key', (string) ($credentials['api_key'] ?? ''))
            ->connectTimeout(3)
            ->timeout(8)
            ->withOptions(['verify' => $verify]);
    }

    private function path(string $endpoint): string
    {
        $prefix = $this->integration->configuration['api_prefix'] ?? '/proxy/network/integration/v1';

        return rtrim((string) $prefix, '/').$endpoint;
    }
}
