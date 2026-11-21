<?php

namespace Tests\Unit;

use App\Models\IntegrationConfig;
use App\Services\Network\ProviderRequestFailed;
use App\Services\Network\UniFiNetworkProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class UniFiProviderTest extends TestCase
{
    private function provider(array $configuration, string $mode = 'read_only'): UniFiNetworkProvider
    {
        return new UniFiNetworkProvider(new IntegrationConfig([
            'provider' => 'unifi', 'mode' => $mode, 'configuration' => $configuration, 'credentials' => ['api_key' => 'test-key'],
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

    public function test_short_console_ids_from_the_web_ui_resolve_to_the_full_connector_id(): void
    {
        Http::fake(['api.ui.com/v1/hosts' => Http::response(['data' => [
            ['id' => 'ABC123:999', 'type' => 'console', 'reportedState' => ['name' => 'School UDM']],
        ]])]);

        $provider = $this->provider(['connection' => 'cloud']);

        $this->assertSame('ABC123:999', $provider->resolveConsoleId('abc123'));
        $this->assertSame('OTHER', $provider->resolveConsoleId('OTHER'));
        $this->assertSame('X:1', $provider->resolveConsoleId('X:1'));
    }

    public function test_content_filter_failures_raise_a_readable_error(): void
    {
        Http::fake(['api.ui.com/*' => Http::response(['code' => 'forbidden', 'message' => 'forbidden: host not found'], 403)]);

        $this->expectException(ProviderRequestFailed::class);
        $this->expectExceptionMessage('no console with this ID');

        $this->provider(['connection' => 'cloud', 'console_id' => 'NOPE'])->contentFilters();
    }

    public function test_writing_lists_puts_the_full_record_with_only_the_lists_changed_and_verifies_it(): void
    {
        $record = ['_id' => 'cf1', 'name' => 'Students', 'enabled' => true, 'allow_list' => ['a.example.org'], 'block_list' => [], 'network_ids' => ['n1'], 'categories' => ['ADULT'], 'schedule' => ['mode' => 'ALWAYS']];
        $saved = $record;
        Http::fake(function ($request) use (&$saved) {
            if ($request->method() === 'PUT') {
                $saved = $request->data();

                return Http::response($saved);
            }

            return Http::response([$saved]);
        });

        $this->provider(['connection' => 'cloud', 'console_id' => 'C:1'], 'active')->writeContentFilterLists('cf1', ['a.example.org', 'b.example.org'], ['bad.example.org']);

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/v1/connector/consoles/C%3A1/proxy/network/v2/api/site/default/content-filtering/cf1')
            && $request['allow_list'] === ['a.example.org', 'b.example.org'] && $request['block_list'] === ['bad.example.org']
            && $request['network_ids'] === ['n1'] && $request['categories'] === ['ADULT'] && $request['schedule'] === ['mode' => 'ALWAYS']);
    }

    public function test_a_write_that_reads_back_differently_or_is_refused_raises_an_error(): void
    {
        $record = ['_id' => 'cf1', 'name' => 'Students', 'allow_list' => [], 'block_list' => []];
        Http::fake(fn ($request) => $request->method() === 'PUT' ? Http::response([], 200) : Http::response([$record]));

        try {
            $this->provider(['connection' => 'cloud', 'console_id' => 'C:1'], 'active')->writeContentFilterLists('cf1', ['x.example.org'], []);
            $this->fail('A write that did not stick must fail.');
        } catch (ProviderRequestFailed $exception) {
            $this->assertStringContainsString('read back differently', $exception->getMessage());
        }
    }

    public function test_verify_write_reports_a_refused_save(): void
    {
        $record = ['_id' => 'cf1', 'name' => 'Students', 'allow_list' => [], 'block_list' => []];
        Http::fake(fn ($request) => $request->method() === 'PUT' ? Http::response('Method Not Allowed', 405) : Http::response([$record]));
        $result = $this->provider(['connection' => 'cloud', 'console_id' => 'C:1'], 'active')->verifyContentFilterWrite('cf1');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('HTTP 405', $result['message']);
    }

    public function test_connection_errors_after_a_filter_save_are_retried_while_dns_reloads(): void
    {
        Sleep::fake();
        Cache::put('unifi.dns_reload_until', time() + 45, 60);
        Http::fake(['unifi.example.test/*' => Http::sequence()
            ->pushFailedConnection()->pushFailedConnection()
            ->push([['_id' => 'f1', 'name' => 'Students', 'allow_list' => [], 'block_list' => ['a.example'], 'network_ids' => []]])]);

        $filters = $this->provider(['connection' => 'local', 'controller_url' => 'https://unifi.example.test'])->contentFilters();

        $this->assertSame(['a.example'], $filters[0]['block']);
        Http::assertSentCount(3);
        Sleep::assertSleptTimes(2);
    }

    public function test_a_console_id_in_the_site_id_field_falls_back_to_the_real_site(): void
    {
        Http::fake([
            'unifi.example.test/proxy/network/integration/v1/sites?*' => Http::response(['data' => [['id' => 'site-uuid', 'internalReference' => 'default', 'name' => 'Default']]]),
            'unifi.example.test/proxy/network/integration/v1/sites' => Http::response(['data' => [['id' => 'site-uuid', 'internalReference' => 'default', 'name' => 'Default']]]),
            'unifi.example.test/proxy/network/integration/v1/sites/site-uuid/traffic-matching-lists*' => Http::response(['data' => [['id' => 'g1', 'name' => 'Threats', 'type' => 'IPV4_ADDRESSES', 'items' => []]]]),
            '*' => Http::response(['message' => 'not a valid siteId'], 400),
        ]);

        $groups = $this->provider(['connection' => 'local', 'controller_url' => 'https://unifi.example.test', 'console_id' => 'ABC:123', 'site_id' => 'ABC:123'])->ipGroups();

        $this->assertSame('Threats', $groups[0]['name']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'ABC'));
    }
}
