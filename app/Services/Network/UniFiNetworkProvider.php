<?php

namespace App\Services\Network;

use App\Models\IntegrationConfig;
use App\Models\WebsiteRequest;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

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

    public function test(): array
    {
        $response = $this->client()->get($this->path('/sites'));

        if (! $response->successful()) {
            return ['ok' => false, 'message' => "UniFi returned HTTP {$response->status()}."];
        }

        return ['ok' => true, 'message' => 'Connected to the official UniFi Network API.', 'details' => ['sites' => $response->json('data', $response->json())]];
    }

    public function apply(WebsiteRequest $request): array
    {
        throw new UnsupportedProviderOperation(
            'This UniFi Network version does not expose a supported domain allowlist operation. Complete the change in UniFi and record the result here.'
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
