<?php

namespace App\Services\Website;

use App\Models\NetworkProfile;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Sends one batch per network probe. Each probe starts a fresh browser for
 * the batch and resolves DNS without caching, so a re-run always reflects
 * the network's current filtering decision.
 */
class WebsiteChecker
{
    /** @return array{results: array<string, array<string, mixed>>, duration_ms: int} */
    public function check(string $url, bool $screenshot = true): array
    {
        $batch = $this->checkMany([['url' => $url, 'hostname' => (string) parse_url($url, PHP_URL_HOST)]], $screenshot);

        return [
            'results' => $batch['checks'][0]['results'],
            'duration_ms' => $batch['duration_ms'],
        ];
    }

    /**
     * @param  list<array{url: string, hostname: string}>  $targets
     * @return array{checks: list<array{url: string, hostname: string, results: array<string, array<string, mixed>>, duration_ms: int}>, duration_ms: int}
     */
    public function checkMany(array $targets, bool $screenshot = true): array
    {
        $profiles = NetworkProfile::query()->where('enabled', true)->orderBy('sort_order')->orderBy('id')->get();

        if ($profiles->isEmpty()) {
            throw new RuntimeException('No network probe profiles are configured.');
        }

        $started = hrtime(true);
        $urls = array_column($targets, 'url');
        $responses = Http::pool(function (Pool $pool) use ($profiles, $urls, $screenshot): array {
            $requests = [];
            foreach ($profiles as $profile) {
                $requests[$profile->key] = $pool->as($profile->key)
                    ->withHeaders(['X-Probe-Key' => (string) config('services.probe.key')])
                    ->connectTimeout(2)->timeout(15)
                    ->post(rtrim($profile->probe_url, '/').'/check-batch', [
                        'urls' => $urls,
                        'screenshot' => $screenshot,
                    ]);
            }

            return $requests;
        });

        $checks = [];
        foreach ($targets as $targetIndex => $target) {
            $results = [];
            foreach ($profiles as $profile) {
                $results[$profile->key] = ['profile' => $profile->name, 'audience' => $profile->audience]
                    + $this->resultFor($responses[$profile->key] ?? null, $targetIndex);
            }

            $checks[] = $target + [
                'results' => $results,
                'duration_ms' => (int) collect($results)->max('duration_ms'),
            ];
        }

        return [
            'checks' => $checks,
            'duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
        ];
    }

    /**
     * Hostnames a page loaded or tried to load, merged across every network,
     * with the networks on which each one failed.
     *
     * @param  array<string, array<string, mixed>>  $results
     * @return list<array{host: string, failed_on: list<string>}>
     */
    public function relatedHosts(array $results): array
    {
        $hosts = [];
        foreach ($results as $key => $result) {
            foreach ($result['hosts'] ?? [] as $host) {
                $name = strtolower((string) ($host['host'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $hosts[$name] ??= ['host' => $name, 'failed_on' => []];
                if (! empty($host['failed'])) {
                    $hosts[$name]['failed_on'][] = (string) $key;
                }
            }
        }

        ksort($hosts);

        return array_values($hosts);
    }

    /** @return array<string, mixed> */
    private function resultFor(mixed $response, int $index): array
    {
        if ($response instanceof Throwable || ! $response instanceof Response) {
            return ['status' => 'probe_error', 'message' => 'Probe unavailable.'];
        }

        if (! $response->successful()) {
            return ['status' => 'probe_error', 'message' => (string) ($response->json('message') ?? 'Probe returned an error.')];
        }

        $result = $response->json("results.{$index}");

        return is_array($result) ? $result : ['status' => 'probe_error', 'message' => 'Probe returned no result.'];
    }
}
