<?php

namespace Tests\Unit;

use App\Models\IntegrationConfig;
use App\Services\Network\UniFiNetworkProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UniFiProviderTest extends TestCase
{
    private function provider(array $configuration): UniFiNetworkProvider
    {
        return new UniFiNetworkProvider(new IntegrationConfig([
            'provider' => 'unifi', 'configuration' => $configuration, 'credentials' => ['api_key' => 'test-key'],
        ]));
    }

    public function test_cloud_connection_routes_through_the_site_manager_connector(): void
    {
        Http::fake([
            'api.ui.com/v1/connector/consoles/CONSOLE1/proxy/network/integration/v1/sites' => Http::response(['data' => [['id' => 'site-1', 'name' => 'Default']]]),
            'api.ui.com/v1/connector/consoles/CONSOLE1/proxy/network/integration/v1/info' => Http::response(['applicationVersion' => '11.0.99']),
        ]);

        $result = $this->provider(['connection' => 'cloud', 'console_id' => 'CONSOLE1'])->test();

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('Site Manager connector', $result['message']);
        $this->assertStringContainsString('11.0.99', $result['message']);
        Http::assertSent(fn ($request) => $request->hasHeader('X-API-Key', 'test-key'));
    }

    public function test_cloud_discovery_lists_consoles_before_one_is_chosen(): void
    {
        Http::fake(['api.ui.com/v1/hosts' => Http::response(['data' => [
            ['id' => 'CONSOLE1', 'type' => 'console', 'reportedState' => ['name' => 'School UDM']],
            ['id' => 'server-1', 'type' => 'network-server', 'reportedState' => ['name' => 'Self-hosted']],
        ]])]);

        $result = $this->provider(['connection' => 'cloud'])->discover();

        $this->assertSame([['id' => 'CONSOLE1', 'name' => 'School UDM']], $result['consoles']);
    }

    public function test_local_401_explains_site_manager_keys(): void
    {
        Http::fake(['unifi.example.test/*' => Http::response(['error' => ['code' => 401]], 401)]);

        $result = $this->provider(['connection' => 'local', 'controller_url' => 'https://unifi.example.test'])->test();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('unifi.ui.com', $result['message']);
    }
}
