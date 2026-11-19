<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\IntegrationConfig;
use App\Models\Setting;
use App\Models\User;
use App\Models\WebsiteRequest;
use App\Notifications\RequestDecisionNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WebsiteRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::query()->create(['key' => 'installation.completed', 'value' => true]);
    }

    public function test_teacher_can_submit_a_normalised_multi_domain_request(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);

        $response = $this->actingAs($teacher)->post(route('requests.store'), [
            'domains' => "https://Example.COM/path\nlearning.example.org",
            'access_scope' => 'staff_students', 'category' => 'Teaching and learning',
            'use_case' => 'Students need these resources for a supervised media literacy lesson.',
            'standards_confirmed' => '1',
        ]);

        $request = WebsiteRequest::query()->firstOrFail();
        $response->assertRedirect(route('requests.show', $request));
        $this->assertSame(['example.com', 'learning.example.org'], $request->domains);
        $this->assertSame(RequestStatus::Pending, $request->status);
        $this->actingAs($teacher)->get(route('requests.show', $request))->assertOk();
    }

    public function test_staff_only_request_can_follow_the_configured_automatic_approval_policy(): void
    {
        Notification::fake();
        $teacher = User::factory()->create(['role' => 'teacher']);
        Setting::query()->create(['key' => 'approval', 'value' => ['auto_approve_staff' => true]]);
        IntegrationConfig::query()->create(['provider' => 'mock', 'name' => 'Simulation', 'mode' => 'active', 'enabled' => true]);

        $this->actingAs($teacher)->post(route('requests.store'), [
            'domains' => 'curriculum.example.org',
            'access_scope' => 'staff',
            'category' => 'Teaching and learning',
            'use_case' => 'Staff require this resource for curriculum planning and lesson preparation.',
            'standards_confirmed' => '1',
        ])->assertRedirect();

        $websiteRequest = WebsiteRequest::query()->firstOrFail();
        $this->assertSame(RequestStatus::Active, $websiteRequest->status);
        $this->assertTrue($websiteRequest->provider_state['simulated']);
        Notification::assertSentTo($teacher, RequestDecisionNotification::class);
    }

    public function test_it_approval_uses_capability_provider_and_notifies_requester(): void
    {
        Notification::fake();
        $teacher = User::factory()->create(['role' => 'teacher']);
        $it = User::factory()->create(['role' => 'it']);
        IntegrationConfig::query()->create(['provider' => 'mock', 'name' => 'Simulation', 'mode' => 'active', 'enabled' => true]);
        $websiteRequest = WebsiteRequest::query()->create([
            'reference' => 'WEB-2026-00001', 'requester_id' => $teacher->id,
            'domains' => ['example.com'], 'access_scope' => 'staff', 'category' => 'Research',
            'use_case' => 'Required for a staff research and curriculum planning activity.',
            'standards_confirmed' => true, 'status' => RequestStatus::Pending,
        ]);

        $this->actingAs($it)->post(route('it.requests.action', $websiteRequest), ['action' => 'approve'])->assertRedirect();
        $this->assertSame(RequestStatus::Active, $websiteRequest->refresh()->status);
        $this->assertTrue($websiteRequest->provider_state['simulated']);
        Notification::assertSentTo($teacher, RequestDecisionNotification::class);
    }

    public function test_teacher_cannot_action_a_request(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $websiteRequest = WebsiteRequest::query()->create([
            'reference' => 'WEB-2026-00002', 'requester_id' => $teacher->id,
            'domains' => ['example.com'], 'access_scope' => 'staff', 'category' => 'Research',
            'use_case' => 'Required for an adequately described curriculum planning activity.',
            'standards_confirmed' => true, 'status' => RequestStatus::Pending,
        ]);

        $this->actingAs($teacher)->post(route('it.requests.action', $websiteRequest), ['action' => 'approve'])->assertForbidden();
    }
}
