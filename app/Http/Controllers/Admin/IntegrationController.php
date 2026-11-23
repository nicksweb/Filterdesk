<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverWebhook;
use App\Models\ApiToken;
use App\Models\WebhookEndpoint;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** API tokens and outgoing webhooks (Settings → Integrations). */
class IntegrationController extends Controller
{
    public function storeToken(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'abilities' => ['required', 'array', 'min:1'], 'abilities.*' => [Rule::in(array_keys(ApiToken::ABILITIES))],
            'expires_days' => ['nullable', 'integer', 'min:1', 'max:730'],
        ]);
        [$token, $plain] = ApiToken::issue($request->user(), $validated['name'], $validated['abilities'], filled($validated['expires_days'] ?? null) ? now()->addDays((int) $validated['expires_days']) : null);
        $audit->log('integration.token_created', $token, ['name' => $token->name, 'abilities' => $token->abilities]);

        return back()->with('success', 'Token created. Copy it now; it is not shown again.')->with('new_token', $plain);
    }

    public function destroyToken(ApiToken $token, AuditLogger $audit): RedirectResponse
    {
        $audit->log('integration.token_revoked', $token, ['name' => $token->name]);
        $token->delete();

        return back()->with('success', 'Token revoked.');
    }

    public function storeWebhook(Request $request, AuditLogger $audit): RedirectResponse
    {
        $endpoint = WebhookEndpoint::query()->create($this->validated($request) + ['enabled' => true]);
        $audit->log('integration.webhook_created', $endpoint, ['name' => $endpoint->name]);

        return back()->with('success', "Webhook {$endpoint->name} added.");
    }

    public function updateWebhook(Request $request, WebhookEndpoint $webhook, AuditLogger $audit): RedirectResponse
    {
        $values = $this->validated($request);
        if (blank($values['secret'] ?? null)) {
            unset($values['secret']);
        }
        $webhook->update($values + ['enabled' => $request->boolean('enabled')]);
        $audit->log('integration.webhook_updated', $webhook, ['name' => $webhook->name]);

        return back()->with('success', "Webhook {$webhook->name} saved.");
    }

    public function destroyWebhook(WebhookEndpoint $webhook, AuditLogger $audit): RedirectResponse
    {
        $audit->log('integration.webhook_deleted', $webhook, ['name' => $webhook->name]);
        $webhook->delete();

        return back()->with('success', 'Webhook removed.');
    }

    public function testWebhook(WebhookEndpoint $webhook): RedirectResponse
    {
        DeliverWebhook::dispatch($webhook->id, 'test', ['title' => 'Test from '.config('app.name'), 'facts' => ['Sent' => now()->local()->format('j M Y g:i a')], 'links' => ['Open' => route('dashboard')]]);

        return back()->with('success', 'Test sent. Refresh to see the result under Last delivery.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'url' => ['required', 'url:https,http', 'max:1000'],
            'format' => ['required', Rule::in(array_keys(WebhookEndpoint::FORMATS))],
            'secret' => ['nullable', 'string', 'max:200'],
            'events' => ['required', 'array', 'min:1'], 'events.*' => [Rule::in(array_keys(WebhookEndpoint::EVENTS))],
        ]);

        return ['name' => $validated['name'], 'url' => $validated['url'], 'format' => $validated['format'], 'secret' => $validated['secret'] ?? null, 'events' => array_values($validated['events'])];
    }
}
