<?php

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\ListScope;
use App\Enums\RequestStatus;
use App\Models\FilterPolicy;
use App\Models\PolicyEntry;
use App\Models\PolicyList;
use App\Models\User;
use App\Notifications\TwoPersonOverrideNotification;
use App\Services\Policy\PolicyEngine;
use App\Services\SettingsRepository;
use App\Support\Reach;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

/**
 * Site-wide changes and sensitive categories need two IT officers; the
 * first officer cannot give the second approval.
 */
class TwoPersonApprovalTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    private PolicyEngine $engine;

    private User $first;

    private User $second;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::flush();
        $this->engine = $this->installWithPolicies();
        $this->first = User::factory()->it()->create(['name' => 'First Officer']);
        $this->second = User::factory()->it()->create(['name' => 'Second Officer']);
    }

    public function test_site_wide_access_needs_a_second_officer(): void
    {
        $request = $this->pendingRequest(['everyone.example.org'], Reach::siteWide());

        $this->actingAs($this->first)->post(route('it.requests.action', $request), ['action' => 'approve'])
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'second IT officer'));
        $this->assertSame(RequestStatus::AwaitingSecondApproval, $request->refresh()->status);
        $this->assertNotContains('everyone.example.org', $this->staffAllow(), 'Not live after one approval');

        // The same officer cannot confirm their own approval.
        $this->actingAs($this->first)->post(route('it.requests.action', $request), ['action' => 'approve'])->assertSessionHas('error');
        $this->assertSame(RequestStatus::AwaitingSecondApproval, $request->refresh()->status);

        $this->actingAs($this->first)->get(route('requests.show', $request))->assertSee('Approve alone (tick the box above)');
        $this->actingAs($this->second)->get(route('requests.show', $request))->assertSee('Give the second approval')->assertSee('Approved by First Officer');

        $this->actingAs($this->second)->post(route('it.requests.action', $request), ['action' => 'approve'])->assertSessionHas('success');
        $this->assertTrue($request->refresh()->status->isGranted());
        $this->assertSame($this->second->id, $request->approved_by_id);
        $this->assertContains('everyone.example.org', $this->staffAllow());
        $this->assertStringContainsString('first approved by First Officer, confirmed by Second Officer', $request->events()->where('type', 'approved')->value('message'));
    }

    public function test_a_sensitive_category_needs_a_second_officer_but_an_ordinary_site_does_not(): void
    {
        $gambling = $this->pendingRequest(['bets.example.org'], Reach::types('staff'), [
            'domain_categories' => ['bets.example.org' => ['ok' => true, 'categories' => ['Gambling'], 'risks' => [], 'unifi' => []]],
        ]);
        $this->actingAs($this->first)->post(route('it.requests.action', $gambling), ['action' => 'approve']);
        $this->assertSame(RequestStatus::AwaitingSecondApproval, $gambling->refresh()->status);
        $this->assertStringContainsString('sensitive category (Gambling)', implode(' ', $gambling->first_approval['reasons']));

        $ordinary = $this->pendingRequest(['maths.example.org'], Reach::types('staff'), [
            'domain_categories' => ['maths.example.org' => ['ok' => true, 'categories' => ['Education'], 'risks' => [], 'unifi' => []]],
        ]);
        $this->actingAs($this->first)->post(route('it.requests.action', $ordinary), ['action' => 'approve']);
        $this->assertTrue($ordinary->refresh()->status->isGranted());
    }

    public function test_with_only_one_it_officer_the_rule_is_skipped_and_noted(): void
    {
        $this->second->update(['enabled' => false]);
        $request = $this->pendingRequest(['solo.example.org'], Reach::siteWide());

        $this->actingAs($this->first)->post(route('it.requests.action', $request), ['action' => 'approve']);

        $this->assertTrue($request->refresh()->status->isGranted());
        $this->assertStringContainsString('Second approval skipped', $request->events()->where('type', 'approved')->value('message'));
    }

    public function test_site_wide_list_changes_are_proposals_until_another_officer_confirms(): void
    {
        $siteBlock = PolicyList::query()->where('scope', ListScope::Site)->where('kind', ListKind::Block)->firstOrFail();

        $this->actingAs($this->first)->post(route('admin.policy.entries.store'), ['policy_list_id' => $siteBlock->id, 'domains' => 'games.example.org'])
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'proposed'));
        $entry = PolicyEntry::query()->where('domain', 'games.example.org')->sole();
        $this->assertSame('add', $entry->pending_action);
        $this->assertNotContains('games.example.org', $this->engine->desired($this->policy('staff'))['block'], 'A proposal is not live');

        $this->actingAs($this->first)->get(route('admin.policy.changes.index'))->assertOk()->assertSee('games.example.org')->assertSee('You proposed this');

        // The proposer cannot confirm it; another officer can.
        $this->actingAs($this->first)->post(route('admin.policy.changes.decide'), ['entries' => [$entry->id], 'decision' => 'confirm'])->assertSessionHas('error');
        $this->assertSame('add', $entry->refresh()->pending_action);
        $this->actingAs($this->second)->post(route('admin.policy.changes.decide'), ['entries' => [$entry->id], 'decision' => 'confirm'])->assertSessionHas('success');
        $this->assertNull($entry->refresh()->pending_action);
        $this->assertContains('games.example.org', $this->engine->desired($this->policy('staff'))['block']);

        // Removing it is a proposal too: it stays live until confirmed.
        $this->actingAs($this->second)->delete(route('admin.policy.entries.destroy', $entry))->assertSessionHas('success', fn (string $message) => str_contains($message, 'proposed'));
        $this->assertContains('games.example.org', $this->engine->desired($this->policy('staff'))['block']);
        $this->actingAs($this->first)->post(route('admin.policy.changes.decide'), ['entries' => [$entry->id], 'decision' => 'confirm']);
        $this->assertModelMissing($entry);
    }

    public function test_a_proposer_can_withdraw_their_own_proposal(): void
    {
        $siteAllow = PolicyList::query()->where('scope', ListScope::Site)->where('kind', ListKind::Allow)->firstOrFail();
        $this->actingAs($this->first)->post(route('admin.policy.entries.store'), ['policy_list_id' => $siteAllow->id, 'domains' => 'oops.example.org']);
        $entry = PolicyEntry::query()->where('domain', 'oops.example.org')->sole();

        $this->actingAs($this->first)->post(route('admin.policy.changes.decide'), ['entries' => [$entry->id], 'decision' => 'reject'])->assertSessionHas('success');
        $this->assertModelMissing($entry);
    }

    public function test_an_officer_can_approve_alone_with_a_reason_and_the_others_are_told(): void
    {
        $request = $this->pendingRequest(['urgent.example.org'], Reach::siteWide());

        $this->actingAs($this->first)->get(route('requests.show', $request))->assertSee('No other IT officer is available?');
        $this->actingAs($this->first)->post(route('it.requests.action', $request), ['action' => 'approve', 'override' => '1'])->assertSessionHasErrors('override_reason');

        $this->actingAs($this->first)->post(route('it.requests.action', $request), ['action' => 'approve', 'override' => '1', 'override_reason' => 'Other officer on leave; exam starts in an hour'])
            ->assertSessionHas('success');

        $this->assertTrue($request->refresh()->status->isGranted());
        $this->assertContains('urgent.example.org', $this->staffAllow());
        $this->assertStringContainsString('without a second IT officer', $request->events()->where('type', 'approved')->value('message'));
        Notification::assertSentTo($this->second, TwoPersonOverrideNotification::class);
        Notification::assertNotSentTo($this->first, TwoPersonOverrideNotification::class);
        $this->assertDatabaseHas('audit_events', ['action' => 'website_request.two_person_override']);
    }

    public function test_the_first_approver_can_finish_alone_with_the_override(): void
    {
        $request = $this->pendingRequest(['waiting.example.org'], Reach::siteWide());
        $this->actingAs($this->first)->post(route('it.requests.action', $request), ['action' => 'approve']);
        $this->assertSame(RequestStatus::AwaitingSecondApproval, $request->refresh()->status);

        $this->actingAs($this->first)->post(route('it.requests.action', $request), ['action' => 'approve', 'override' => '1', 'override_reason' => 'Second officer unreachable']);

        $this->assertTrue($request->refresh()->status->isGranted());
    }

    public function test_the_override_can_be_turned_off(): void
    {
        app(SettingsRepository::class)->set('approval.two_person_override', false);
        $request = $this->pendingRequest(['off.example.org'], Reach::siteWide());

        $this->actingAs($this->first)->get(route('requests.show', $request))->assertDontSee('No other IT officer is available?');
        $this->actingAs($this->first)->post(route('it.requests.action', $request), ['action' => 'approve', 'override' => '1', 'override_reason' => 'x'])->assertSessionHas('error');
        $this->assertSame(RequestStatus::Pending, $request->refresh()->status);
    }

    public function test_a_proposer_can_confirm_their_own_site_wide_change_with_the_override(): void
    {
        $siteBlock = PolicyList::query()->where('scope', ListScope::Site)->where('kind', ListKind::Block)->firstOrFail();
        $this->actingAs($this->first)->post(route('admin.policy.entries.store'), ['policy_list_id' => $siteBlock->id, 'domains' => 'malware.example.org']);
        $entry = PolicyEntry::query()->where('domain', 'malware.example.org')->sole();

        $this->actingAs($this->first)->post(route('admin.policy.changes.decide'), ['entries' => [$entry->id], 'decision' => 'confirm', 'override' => '1', 'override_reason' => 'Active malware outbreak'])
            ->assertSessionHas('success');

        $this->assertNull($entry->refresh()->pending_action);
        Notification::assertSentTo($this->second, TwoPersonOverrideNotification::class);
    }

    /** @return list<string> */
    private function staffAllow(): array
    {
        return $this->engine->desired($this->policy('staff'))['allow'];
    }

    private function policy(string $audience): FilterPolicy
    {
        return FilterPolicy::query()->where('audience', $audience)->whereNull('layer_of_id')->firstOrFail();
    }
}
