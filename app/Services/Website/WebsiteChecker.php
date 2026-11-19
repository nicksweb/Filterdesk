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
        $profiles = NetworkProfile::query()->where('enabled', true)->get();

        if ($profiles->isEmpty()) {
            throw new RuntimeException('No network probe profiles are configured.');
        }

        $started = hrtime(true);
        $responses = Http::pool(function (Pool $pool) use ($profiles, $url, $screenshot): array {
            return $profiles->mapWithKeys(fn (NetworkProfile $profile) => [
                $profile->key => $pool->as($profile->key)
                    ->withHeaders(['X-Probe-Key' => (string) config('services.probe.key')])
                    ->connectTimeout(1)->timeout(4)
                    ->post(rtrim($profile->probe_url, '/').'/check', [
                        'url' => $url,
                        'screenshot' => $screenshot,
                    ]),
            ])->all();
        });

        $results = [];
        foreach ($profiles as $profile) {
            $response = $responses[$profile->key] ?? null;
            if ($response instanceof \Throwable) {
                $results[$profile->key] = ['profile' => $profile->name, 'status' => 'probe_error', 'message' => 'Probe unavailable.'];

                continue;
            }

            $payload = $response?->json() ?? [];
            $results[$profile->key] = ['profile' => $profile->name] + ($response?->successful()
                ? $payload
                : ['status' => 'probe_error', 'message' => $payload['message'] ?? 'Probe returned an error.']);
        }

        return [
            'results' => $results,
            'duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
        ];
    }
}
