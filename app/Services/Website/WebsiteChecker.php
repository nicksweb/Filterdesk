<?php

namespace App\Services\Website;

use App\Models\NetworkProfile;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use RuntimeException;

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
        $profiles = NetworkProfile::query()->where('enabled', true)->get();

        if ($profiles->isEmpty()) {
            throw new RuntimeException('No network probe profiles are configured.');
        }

        $started = hrtime(true);
        $responses = Http::pool(function (Pool $pool) use ($profiles, $targets, $screenshot): array {
            $requests = [];
            foreach ($targets as $targetIndex => $target) {
                foreach ($profiles as $profile) {
                    $key = "{$targetIndex}:{$profile->key}";
                    $requests[$key] = $pool->as($key)
                        ->withHeaders(['X-Probe-Key' => (string) config('services.probe.key')])
                        ->connectTimeout(1)->timeout(4)->retry(2, 100, throw: false)
                        ->post(rtrim($profile->probe_url, '/').'/check', [
                            'url' => $target['url'],
                            'screenshot' => $screenshot,
                        ]);
                }
            }

            return $requests;
        });

        $checks = [];
        foreach ($targets as $targetIndex => $target) {
            $results = [];
            foreach ($profiles as $profile) {
                $response = $responses["{$targetIndex}:{$profile->key}"] ?? null;
                if ($response instanceof \Throwable) {
                    $results[$profile->key] = ['profile' => $profile->name, 'status' => 'probe_error', 'message' => 'Probe unavailable.'];

                    continue;
                }

                $payload = $response?->json() ?? [];
                $results[$profile->key] = ['profile' => $profile->name] + ($response?->successful()
                    ? $payload
                    : ['status' => 'probe_error', 'message' => $payload['message'] ?? 'Probe returned an error.']);
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
}
