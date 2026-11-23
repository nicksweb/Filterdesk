<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\NetworkType;
use App\Models\User;
use App\Models\WebsiteRequest;
use App\Notifications\RequestDecisionNotification;
use App\Services\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

/** After access is approved, staff are shown what to do while the change reaches the network. */
class AccessGuideTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::flush();
        $this->installWithPolicies();
        app(SettingsRepository::class)->set('approval.auto_approve_staff', true);
        NetworkType::query()->where('key', 'staff')->update(['auto_approve' => true]);
        NetworkType::flushCache();
    }

    private function submit(User $user, array $types = ['staff']): TestResponse
    {
        return $this->actingAs($user)->post(route('requests.store'), [
            'domains' => 'maps.example.org', 'network_types' => $types, 'category' => 'Teaching and learning',
            'use_case' => 'Geography mapping lesson for Year 9', 'access_duration' => 'permanent', 'standards_confirmed' => '1',
        ]);
    }

    public function test_an_auto_approved_staff_request_goes_to_the_guide(): void
    {
        $teacher = User::factory()->teacher()->create();

        $response = $this->submit($teacher);
        $request = WebsiteRequest::query()->sole();

        $response->assertRedirect(route('requests.ready', $request));
        $this->actingAs($teacher)->get(route('requests.ready', $request))->assertOk()
            ->assertSee('Your access is on its way')->assertSee('Close every browser window')->assertSee('Restart your computer');
        $this->actingAs($teacher)->get(route('requests.show', $request))->assertSee('Site still looks blocked?');
        Notification::assertSentTo($teacher, RequestDecisionNotification::class, fn ($notification, $channels, $notifiable) => str_contains(implode(' ', $notification->toMail($notifiable)->introLines), 'Close every browser window'));
    }

    public function test_requests_that_wait_for_it_go_to_the_request_page(): void
    {
        $teacher = User::factory()->teacher()->create();

        $this->submit($teacher, ['staff', 'student'])->assertRedirect(route('requests.show', WebsiteRequest::query()->sole()));
        $this->actingAs($teacher)->get(route('requests.ready', WebsiteRequest::query()->sole()))->assertRedirect(route('requests.show', WebsiteRequest::query()->sole()));
    }

    public function test_students_do_not_see_it_unless_turned_on_and_the_wording_is_editable(): void
    {
        $student = User::factory()->student()->create();
        $request = $this->pendingRequest(['games.example.org'], null, ['requester_id' => $student->id, 'status' => RequestStatus::Active]);

        $this->actingAs($student)->get(route('requests.ready', $request))->assertRedirect(route('requests.show', $request));

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->put(route('admin.settings.update', 'approval'), [
            'default_temporary_days' => 30, 'categories' => 'Teaching and learning',
            'guide' => ['enabled' => '1', 'students' => '1', 'title' => 'Nearly there', 'steps' => "Close Chrome\nRestart the laptop"],
        ])->assertSessionHas('success');

        $this->actingAs($student)->get(route('requests.ready', $request))->assertOk()->assertSee('Nearly there')->assertSee('Restart the laptop');
    }
}
