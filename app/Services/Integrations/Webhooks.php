<?php

namespace App\Services\Integrations;

use App\Jobs\DeliverWebhook;
use App\Models\PolicyRelease;
use App\Models\WebhookEndpoint;
use App\Models\WebsiteRequest;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Queues request and policy events for every enabled endpoint that wants
 * them. Never lets a webhook problem affect the request or push itself.
 */
class Webhooks
{
    public function request(string $event, WebsiteRequest $request): void
    {
        $this->send($event, fn () => [
            'title' => match ($event) {
                'request.submitted' => "New request {$request->reference}: ".implode(', ', $request->domains),
                'request.awaiting_second' => "Second approval needed: {$request->reference}",
                'request.approved' => "Approved: {$request->reference}",
                'request.declined' => "Declined: {$request->reference}",
                default => $request->reference,
            },
            'reference' => $request->reference,
            'type' => $request->isBlock() ? 'block' : 'access',
            'status' => $request->status->value,
            'websites' => $request->domains,
            'networks' => $request->access_scope?->value(),
            'category' => $request->category,
            'department' => $request->department,
            'requester' => $request->requester?->name,
            'url' => route('requests.show', $request),
            'facts' => array_filter([
                'Websites' => implode(', ', $request->domains), 'Networks' => $request->access_scope?->label(), 'Category' => $request->category,
                'Requested by' => $request->requester?->name, 'Status' => $request->status->label(),
            ]),
            'links' => array_filter([
                'Open request' => route('requests.show', $request),
                'Approve' => $request->status->isOpen() ? URL::temporarySignedRoute('it.requests.quick', now()->addDays(7), ['websiteRequest' => $request, 'decision' => 'approve']) : null,
                'Decline' => $request->status->isOpen() ? URL::temporarySignedRoute('it.requests.quick', now()->addDays(7), ['websiteRequest' => $request, 'decision' => 'reject']) : null,
            ]),
        ]);
    }

    public function published(PolicyRelease $release): void
    {
        $this->send('policy.published', fn () => [
            'title' => "Policy {$release->label()} published",
            'version' => $release->number, 'trigger' => $release->trigger, 'status' => $release->status, 'summary' => $release->summary,
            'url' => route('admin.policy.versions.show', $release),
            'facts' => ['Version' => $release->label(), 'How' => $release->triggerLabel(), 'Changes' => (string) $release->summary],
            'links' => ['Open version' => route('admin.policy.versions.show', $release)],
        ]);
    }

    /** @param callable(): array<string, mixed> $payload */
    private function send(string $event, callable $payload): void
    {
        try {
            $endpoints = WebhookEndpoint::query()->where('enabled', true)->get()->filter(fn (WebhookEndpoint $endpoint) => in_array($event, $endpoint->events ?? [], true));
            if ($endpoints->isEmpty()) {
                return;
            }
            $data = $payload();
            foreach ($endpoints as $endpoint) {
                DeliverWebhook::dispatch($endpoint->id, $event, $data);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
