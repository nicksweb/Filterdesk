<?php

namespace Tests\Feature;

use App\Jobs\DeliverWebhook;
use App\Models\ApiToken;
use App\Models\FilterPolicy;
use App\Models\IntegrationConfig;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\Policy\PolicyEngine;
use App\Services\Policy\PolicyPublisher;
use App\Support\Reach;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

/** The API, webhooks (JSON and Teams) and one-click decision links. */
class IntegrationsTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    private User $it;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::flush();
        $this->installWithPolicies();
        $this->it = User::factory()->it()->create();
    }

    public function test_api_tokens_read_create_and_decide_requests_with_their_abilities(): void
    {
        [, $read] = ApiToken::issue($this->it, 'Dashboard', ['read']);
        [, $all] = ApiToken::issue($this->it, 'Service desk', ['read', 'requests', 'decide']);
        $teacher = User::factory()->teacher()->create(['email' => 'teacher@example.test']);
        $request = $this->pendingRequest(['maths.example.org'], Reach::types('staff'));

        $this->getJson('/api/v1/requests')->assertUnauthorized();
        $this->withToken($read)->getJson('/api/v1/requests')->assertOk()->assertJsonPath('data.0.reference', $request->reference);
        $this->withToken($read)->postJson("/api/v1/requests/{$request->reference}/decision", ['decision' => 'approve'])->assertForbidden();

        $this->withToken($all)->postJson('/api/v1/requests', [
            'requester_email' => 'teacher@example.test', 'websites' => ['https://science.example.org/lab'], 'network_types' => ['staff'], 'category' => 'Teaching and learning', 'reason' => 'From the service desk',
        ])->assertCreated()->assertJsonPath('data.websites.0', 'science.example.org')->assertJsonPath('data.requester.email', $teacher->email);

        $this->withToken($all)->postJson("/api/v1/requests/{$request->reference}/decision", ['decision' => 'approve'])->assertOk()->assertJsonPath('data.status', fn (string $status) => $status !== 'pending');
        $this->assertSame($this->it->id, $request->refresh()->approved_by_id, 'Recorded against the token owner');
        $this->withToken($all)->getJson('/api/v1/explain?site=maths.example.org&type=staff')->assertOk()->assertJsonStructure(['data' => ['verdict', 'reason', 'policies']]);
    }

    public function test_a_disabled_owner_or_revoked_token_stops_working(): void
    {
        [$token, $plain] = ApiToken::issue($this->it, 'Old', ['read']);
        $this->it->update(['enabled' => false]);
        $this->withToken($plain)->getJson('/api/v1/requests')->assertUnauthorized();
        $this->it->update(['enabled' => true]);
        $token->delete();
        $this->withToken($plain)->getJson('/api/v1/requests')->assertUnauthorized();
    }

    public function test_webhooks_are_signed_json_or_teams_cards_with_decision_links(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        WebhookEndpoint::query()->create(['name' => 'Desk', 'url' => 'https://desk.example.test/hook', 'format' => 'json', 'secret' => 's3cret', 'events' => ['request.submitted'], 'enabled' => true]);
        WebhookEndpoint::query()->create(['name' => 'Teams', 'url' => 'https://teams.example.test/hook', 'format' => 'teams', 'events' => ['request.submitted'], 'enabled' => true]);
        $teacher = User::factory()->teacher()->create();

        $this->actingAs($teacher)->post(route('requests.store'), [
            'domains' => 'new.example.org', 'network_types' => ['staff'], 'category' => 'Teaching and learning', 'use_case' => 'Needed for a Year 8 history unit',
            'access_duration' => 'permanent', 'standards_confirmed' => '1',
        ]);

        Http::assertSent(function (ClientRequest $sent) {
            return str_contains($sent->url(), 'desk.example.test') && $sent['event'] === 'request.submitted'
                && $sent->header('X-ITRequests-Signature')[0] === 'sha256='.hash_hmac('sha256', $sent->body(), 's3cret');
        });
        Http::assertSent(fn (ClientRequest $sent) => str_contains($sent->url(), 'teams.example.test') && $sent['@type'] === 'MessageCard'
            && collect($sent['potentialAction'])->pluck('name')->contains('Approve'));
    }

    public function test_a_quick_decision_link_needs_a_signature_and_an_it_sign_in(): void
    {
        $request = $this->pendingRequest(['quick.example.org'], Reach::types('staff'));
        $signed = URL::temporarySignedRoute('it.requests.quick', now()->addDay(), ['websiteRequest' => $request, 'decision' => 'approve']);

        $this->get($signed)->assertRedirect(route('login'));
        $this->actingAs($this->it)->get(route('it.requests.quick', ['websiteRequest' => $request, 'decision' => 'approve']))->assertForbidden();
        $this->actingAs($this->it)->get($signed)->assertOk()->assertSee('Approve as requested');
        $this->actingAs(User::factory()->teacher()->create())->get($signed)->assertForbidden();
    }

    public function test_publishing_queues_a_policy_webhook(): void
    {
        Queue::fake();
        WebhookEndpoint::query()->create(['name' => 'Desk', 'url' => 'https://desk.example.test/hook', 'format' => 'json', 'events' => ['policy.published'], 'enabled' => true]);
        app(PolicyEngine::class)->upsertEntry(app(PolicyEngine::class)->defaultAllowList('student'), 'x.example.org', ['source' => 'manual']);
        FilterPolicy::query()->where('audience', 'student')->update(['provider_reference' => 'sim-filter-students']);
        IntegrationConfig::query()->where('provider', 'mock')->update(['mode' => 'active']);

        app(PolicyPublisher::class)->publishAll($this->it);

        Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job) => $job->event === 'policy.published');
    }

    public function test_metrics_need_a_token_and_report_prometheus_gauges(): void
    {
        [, $read] = ApiToken::issue($this->it, 'Monitoring', ['read']);
        $this->pendingRequest(['open.example.org'], Reach::types('staff'));

        $this->get('/api/v1/metrics')->assertUnauthorized();
        $body = $this->withToken($read)->get('/api/v1/metrics')->assertOk()->getContent();
        $this->assertStringContainsString('itrequests_requests_open 1', $body);
        $this->assertStringContainsString('# TYPE itrequests_reviews_overdue gauge', $body);
    }

    public function test_the_compatibility_check_reads_every_call_the_app_uses(): void
    {
        $this->artisan('unifi:compat')->expectsOutputToContain('Content filters')->expectsOutputToContain('Firewall zones')->assertSuccessful();
    }
}
