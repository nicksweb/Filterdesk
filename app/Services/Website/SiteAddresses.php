<?php

namespace App\Services\Website;

use App\Models\NetworkProfile;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * The addresses a hostname resolves to on each network, asked of that
 * network's probe (its own DNS, so the school's resolvers and the content
 * filter's sinkhole answers), with an optional connection test to each
 * address. No browser, so it takes a second or two. When no probe answers,
 * the app's own DNS is used and the result says so.
 */
class SiteAddresses
{
    public function __construct(private readonly ProbeSettings $settings) {}

    /**
     * @param  Collection<int, NetworkProfile>|null  $profiles  the networks to ask; every enabled probe when null
     * @return list<array{profile: ?NetworkProfile, network: string, addresses: list<string>, blocked: bool, resolver: string, error: ?string, connect: array<string, string>}>
     */
    public function resolve(string $host, ?Collection $profiles = null, bool $connect = false): array
    {
        $host = strtolower(trim($host));
        $profiles ??= NetworkProfile::query()->where('enabled', true)->whereNotNull('probe_url')->orderBy('sort_order')->get();
        $profiles = $profiles->filter(fn (NetworkProfile $profile) => filled($profile->probe_url))->values();
        $answers = [];
        if ($profiles->isNotEmpty()) {
            $responses = Http::pool(fn (Pool $pool) => $profiles->map(fn (NetworkProfile $profile) => $pool->as((string) $profile->id)
                ->connectTimeout(2)->timeout($connect ? 8 : 5)->withHeaders(['X-Probe-Key' => (string) config('services.probe.key')])
                ->post(rtrim((string) $profile->probe_url, '/').'/resolve', ['host' => $host, 'block_addresses' => $this->settings->blockAddresses(), 'connect' => $connect]))->all());
            foreach ($profiles as $profile) {
                $response = $responses[(string) $profile->id] ?? null;
                $json = $response instanceof Response && $response->successful() ? (array) $response->json() : null;
                $answers[] = [
                    'profile' => $profile, 'network' => $profile->name,
                    'addresses' => array_values(array_filter((array) ($json['addresses'] ?? []), fn ($address) => filter_var($address, FILTER_VALIDATE_IP) !== false)),
                    'blocked' => (bool) ($json['blocked'] ?? false),
                    'resolver' => (string) ($json['resolver'] ?? ''),
                    'error' => $json === null ? 'The probe did not answer.' : (isset($json['error']) ? (string) $json['error'] : null),
                    'connect' => array_map('strval', (array) ($json['connect'] ?? [])),
                ];
            }
        }
        if (config('services.probe.local_dns_fallback', true) && collect($answers)->every(fn (array $answer) => $answer['error'] === 'The probe did not answer.')) {
            $addresses = array_values(array_unique([...(gethostbynamel($host) ?: []), ...collect(rescue(fn () => dns_get_record($host, DNS_AAAA), [], false) ?: [])->pluck('ipv6')->filter()->all()]));
            $answers[] = ['profile' => null, 'network' => 'This server', 'addresses' => $addresses, 'blocked' => false, 'resolver' => "this server's DNS (no probe answered)", 'error' => $addresses === [] ? 'NXDOMAIN' : null, 'connect' => []];
        }

        return $answers;
    }
}
