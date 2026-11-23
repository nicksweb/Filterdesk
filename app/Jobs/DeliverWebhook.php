<?php

namespace App\Jobs;

use App\Models\WebhookEndpoint;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sends one event to one endpoint. JSON bodies carry an HMAC-SHA256 signature
 * of the raw body in X-Filterdesk-Signature (sha256=…) when a secret is set;
 * Teams endpoints get a MessageCard with a button to the request.
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    /** @param array<string, mixed> $payload */
    public function __construct(public readonly int $endpointId, public readonly string $event, public readonly array $payload) {}

    public function handle(): void
    {
        $endpoint = WebhookEndpoint::query()->find($this->endpointId);
        if (! $endpoint || ! $endpoint->enabled) {
            return;
        }

        $body = $endpoint->format === 'teams' ? $this->teamsCard() : ['event' => $this->event, 'sent_at' => now()->toIso8601String(), 'data' => $this->payload];
        $json = (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $headers = ['Content-Type' => 'application/json', 'User-Agent' => 'Filterdesk-Webhook', 'X-Filterdesk-Event' => $this->event];
        if ($endpoint->format === 'json' && filled($endpoint->secret)) {
            $headers['X-Filterdesk-Signature'] = 'sha256='.hash_hmac('sha256', $json, (string) $endpoint->secret);
        }

        try {
            $response = Http::withHeaders($headers)->connectTimeout(5)->timeout(15)->withBody($json, 'application/json')->post($endpoint->url);
            $status = $response->successful() ? 'OK '.$response->status() : 'HTTP '.$response->status();
        } catch (Throwable $exception) {
            $status = 'Could not connect';
        }
        $endpoint->forceFill(['last_delivered_at' => now(), 'last_status' => "{$this->event}: {$status}"])->save();

        if (! str_starts_with($status, 'OK')) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 300);
        }
    }

    /** @return array<string, mixed> */
    private function teamsCard(): array
    {
        $facts = collect($this->payload['facts'] ?? [])->map(fn ($value, $name) => ['name' => (string) $name, 'value' => (string) $value])->values()->all();
        $actions = collect($this->payload['links'] ?? [])->map(fn (string $url, string $label) => ['@type' => 'OpenUri', 'name' => $label, 'targets' => [['os' => 'default', 'uri' => $url]]])->values()->all();

        return [
            '@type' => 'MessageCard', '@context' => 'https://schema.org/extensions', 'themeColor' => '176BDB',
            'summary' => (string) ($this->payload['title'] ?? $this->event),
            'sections' => [['activityTitle' => (string) ($this->payload['title'] ?? $this->event), 'activitySubtitle' => config('app.name'), 'facts' => $facts, 'markdown' => true]],
            'potentialAction' => $actions,
        ];
    }
}
