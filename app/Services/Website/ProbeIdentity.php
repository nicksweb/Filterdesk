<?php

namespace App\Services\Website;

use App\Models\NetworkProfile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Asks a probe container for its addresses and MACs, so IT can find it in
 * UniFi (name it, reserve its address) and confirm it sits on the right
 * VLAN: the interface with the default route should be on the probe's
 * network, with that network's gateway.
 */
class ProbeIdentity
{
    /**
     * @return array{ok: bool, message?: string, hostname?: string, gateway?: ?string, dns?: list<string>, dns_mode?: string,
     *     interfaces?: list<array{name: string, mac: ?string, ipv4: list<string>, default_route: bool}>,
     *     vlan?: ?array{name: string, mac: ?string, ipv4: list<string>, default_route: bool}}
     */
    public function for(NetworkProfile $profile, bool $refresh = false): array
    {
        $key = "probe.identity.{$profile->id}";
        if ($refresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, 60, function () use ($profile): array {
            try {
                $response = Http::connectTimeout(1)->timeout(3)
                    ->withHeaders(['X-Probe-Key' => (string) config('services.probe.key')])
                    ->get(rtrim($profile->probe_url, '/').'/identity');
            } catch (Throwable) {
                return ['ok' => false, 'message' => 'Probe not reachable.'];
            }
            if ($response->status() === 404) {
                return ['ok' => false, 'message' => 'This probe image is too old to report its addresses; rebuild it.'];
            }
            $body = $response->json();
            if (! $response->successful() || ! is_array($body) || ! is_array($body['interfaces'] ?? null)) {
                return ['ok' => false, 'message' => 'Probe did not report its addresses (HTTP '.$response->status().').'];
            }

            $interfaces = collect($body['interfaces'])->filter(fn ($item) => is_array($item) && isset($item['name']))->map(fn (array $item) => [
                'name' => (string) $item['name'],
                'mac' => isset($item['mac']) ? (string) $item['mac'] : null,
                'ipv4' => array_values(array_map('strval', (array) ($item['ipv4'] ?? []))),
                'default_route' => (bool) ($item['default_route'] ?? false),
            ])->values()->all();

            return [
                'ok' => true,
                'hostname' => (string) ($body['hostname'] ?? ''),
                'gateway' => isset($body['gateway']) ? (string) $body['gateway'] : null,
                'dns' => array_values(array_map('strval', (array) ($body['dns'] ?? []))),
                'dns_mode' => (string) ($body['dns_mode'] ?? 'system'),
                'interfaces' => $interfaces,
                'vlan' => collect($interfaces)->firstWhere('default_route', true),
            ];
        });
    }
}
