<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\PolicyEntry;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\RequestActivityNotification;
use App\Notifications\RequestDecisionNotification;
use App\Support\Reach;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

class ItRequestActionTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->installWithPolicies();
    }

    public function test_assignee_dropdown_lists_only_enabled_it_staff(): void
    {
        $it = User::factory()->it()->create(['name' => 'Ivy Tech']);
        User::factory()->it()->disabled()->create(['name' => 'Gone Person']);
        User::factory()->teacher()->create(['name' => 'Terry Teacher']);
        $request = $this->pendingRequest();

        $this->actingAs($it)->get(route('requests.show', $request))
            ->assertOk()
            ->assertSee('Ivy Tech')
            ->assertDontSee('Gone Person')
            ->assertSee('Assign to me')
            ->assertDontSee('User ID');
    }

    public function test_requests_cannot_be_assigned_to_non_it_users(): void
    {
        $it = User::factory()->it()->create();
        $teacher = User::factory()->teacher()->create();
        $request = $this->pendingRequest();

        $this->actingAs($it)->from(route('requests.show', $request))
            ->post(route('it.requests.action', $request), ['action' => 'assign', 'assignee_id' => $teacher->id])
            ->assertSessionHasErrors('assignee_id');

        $this->assertNull($request->refresh()->assigned_to_id);
    }

    public function test_assignment_notifies_the_assignee_and_assign_to_me_works(): void
    {
        $it = User::factory()->it()->create();
        $colleague = User::factory()->it()->create();
        $request = $this->pendingRequest();

        $this->actingAs($it)->post(route('it.requests.action', $request), ['action' => 'assign', 'assignee_id' => $colleague->id])->assertSessionHas('success');
        $this->assertSame($colleague->id, $request->refresh()->assigned_to_id);
        Notification::assertSentTo($colleague, RequestActivityNotification::class);

        $this->actingAs($it)->post(route('it.requests.action', $request), ['action' => 'assign_me'])->assertSessionHas('success');
        $this->assertSame($it->id, $request->refresh()->assigned_to_id);
        Notification::assertNotSentTo($it, RequestActivityNotification::class);
    }

    public function test_approval_uses_selected_hostnames_and_scope_override(): void
    {
        $it = User::factory()->it()->create();
        $request = $this->pendingRequest(['learn.example.edu.au'], Reach::types('staff', 'student'));

        $this->actingAs($it)->post(route('it.requests.action', $request), [
            'action' => 'approve',
            'domains' => ['learn.example.edu.au', 'example.edu.au'],
            'extra_domains' => "https://cdn.example.net/assets\n",
            'scope' => 'staff',
        ])->assertSessionHas('success');

        $request->refresh();
        $this->assertSame(RequestStatus::ActionRequired, $request->status);
        $this->assertSame(['cdn.example.net', 'example.edu.au', 'learn.example.edu.au'], $request->approved_domains);
        $this->assertSame('staff', $request->applied_scope->value());
        $this->assertSame(3, PolicyEntry::query()->where('website_request_id', $request->id)->count());
        Notification::assertSentTo($request->requester, RequestDecisionNotification::class);
    }

    public function test_actions_respect_the_current_status(): void
    {
        $it = User::factory()->it()->create();
        $request = $this->pendingRequest(['example.com'], Reach::types('staff'), ['status' => RequestStatus::Rejected]);

        $this->actingAs($it)->post(route('it.requests.action', $request), ['action' => 'approve'])->assertSessionHas('error');
        $this->assertSame(RequestStatus::Rejected, $request->refresh()->status);
    }

    public function test_internal_notes_are_hidden_from_the_requester(): void
    {
        $it = User::factory()->it()->create();
        $request = $this->pendingRequest();
        $this->actingAs($it)->post(route('it.requests.action', $request), ['action' => 'note', 'message' => 'Checked with the curriculum lead privately.']);

        $this->actingAs($it)->get(route('requests.show', $request))->assertSee('Checked with the curriculum lead privately.');
        $this->actingAs($request->requester)->get(route('requests.show', $request))->assertOk()->assertDontSee('Checked with the curriculum lead privately.');
    }

    public function test_mark_applied_and_revoke_complete_the_lifecycle(): void
    {
        $it = User::factory()->it()->create();
        $request = $this->pendingRequest();
        $this->actingAs($it)->post(route('it.requests.action', $request), ['action' => 'approve']);
        $this->actingAs($it)->post(route('it.requests.action', $request), ['action' => 'applied']);
        $this->assertSame(RequestStatus::Active, $request->refresh()->status);

        $this->actingAs($it)->post(route('it.requests.action', $request), ['action' => 'revoke', 'message' => 'Unit finished.'])->assertSessionHas('success');
        $this->assertSame(RequestStatus::Revoked, $request->refresh()->status);
        $this->assertSame(0, PolicyEntry::query()->where('website_request_id', $request->id)->count());
    }

    public function test_new_requests_email_it_and_students_cannot_request_by_default(): void
    {
        Setting::query()->create(['key' => 'notifications', 'value' => ['it_address' => 'it@example.edu.au']]);
        $teacher = User::factory()->teacher()->create();
        $student = User::factory()->student()->create();
        $payload = [
            'domains' => 'example.com', 'access_scope' => 'staff', 'category' => 'Research',
            'use_case' => 'Needed for planning a research unit with colleagues.', 'standards_confirmed' => '1', 'access_duration' => 'permanent',
        ];

        $this->actingAs($teacher)->post(route('requests.store'), $payload)->assertRedirect();
        Notification::assertSentOnDemand(RequestActivityNotification::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'it@example.edu.au');

        $this->actingAs($student)->get(route('requests.create'))->assertRedirect(route('checks.create'));
        $this->actingAs($student)->post(route('requests.store'), $payload)->assertForbidden();
    }

    public function test_request_index_filters_by_status_and_assignment(): void
    {
        $it = User::factory()->it()->create();
        $mine = $this->pendingRequest(['mine.example.org'], Reach::types('staff'), ['assigned_to_id' => $it->id]);
        $this->pendingRequest(['unassigned.example.org']);
        $this->pendingRequest(['closed.example.org'], Reach::types('staff'), ['status' => RequestStatus::Rejected]);

        $this->actingAs($it)->get(route('requests.index', ['status' => 'open', 'assigned' => 'me']))
            ->assertOk()->assertSee($mine->reference)->assertDontSee('unassigned.example.org')->assertDontSee('closed.example.org');
        $this->actingAs($it)->get(route('requests.index', ['status' => 'all', 'q' => 'closed']))
            ->assertOk()->assertSee('closed.example.org')->assertDontSee('mine.example.org');
    }
}
