<?php

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\RequestStatus;
use App\Models\FilterPolicy;
use App\Models\IntegrationConfig;
use App\Models\PolicyEntry;
use App\Models\User;
use App\Notifications\RequestDecisionNotification;
use App\Services\Network\MockNetworkProvider;
use App\Services\Policy\PolicyEngine;
use App\Services\Requests\RequestWorkflow;
use App\Services\SettingsRepository;
use App\Support\Reach;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

class TimedAccessTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    private PolicyEngine $engine;

    private FilterPolicy $students;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::flush();
        $this->engine = $this->installWithPolicies('active');
        $this->students = FilterPolicy::query()->where('audience', 'student')->firstOrFail();
        $this->students->update(['provider_reference' => 'sim-filter-students']);
        app(SettingsRepository::class)->set('notifications.it_address', 'it@example.test');
        $this->freezeTime();
    }

    private function studentFilterAllows(string $domain): bool
    {
        return in_array($domain, collect((new MockNetworkProvider)->contentFilters())->firstWhere('id', 'sim-filter-students')['allow'], true);
    }

    public function test_timed_entries_go_live_five_minutes_early_and_leave_five_minutes_late(): void
    {
        $list = $this->engine->defaultAllowList('student');
        $entry = $this->engine->upsertEntry($list, 'lesson.example.org', ['source' => 'manual', 'starts_at' => now()->addMinutes(30), 'expires_at' => now()->addMinutes(90)]);

        $this->travel(24)->minutes();
        $this->assertNotContains('lesson.example.org', $this->engine->desired($this->students)['allow']);
        $this->travel(2)->minutes();
        $this->assertContains('lesson.example.org', $this->engine->desired($this->students)['allow'], 'Live from start minus 5 minutes');
        $this->assertTrue($entry->refresh()->isEffective());

        $this->travel(68)->minutes();
        $this->assertContains('lesson.example.org', $this->engine->desired($this->students)['allow'], 'Still live until end plus 5 minutes');
        $this->travel(2)->minutes();
        $this->assertNotContains('lesson.example.org', $this->engine->desired($this->students)['allow']);
    }

    public function test_the_minute_scheduler_pushes_only_when_a_window_opens_or_closes(): void
    {
        IntegrationConfig::query()->where('provider', 'mock')->update(['configuration' => ['auto_push' => true, 'controller_authoritative' => false]]);
        $block = $this->engine->ownList($this->students, ListKind::Block);
        $this->engine->upsertEntry($this->engine->defaultAllowList('student'), 'exam-tool.example.org', ['source' => 'manual', 'starts_at' => now()->addMinutes(10), 'expires_at' => now()->addMinutes(40)]);
        $this->engine->upsertEntry($block, 'chat.example.org', ['source' => 'manual', 'starts_at' => now()->addMinutes(10), 'expires_at' => now()->addMinutes(40)]);

        $this->artisan('policy:push --boundaries')->assertSuccessful();
        $this->assertFalse($this->studentFilterAllows('exam-tool.example.org'));

        $this->travel(5)->minutes();
        $this->artisan('policy:push --boundaries')->assertSuccessful();
        $this->assertTrue($this->studentFilterAllows('exam-tool.example.org'), 'Opened at start minus 5 minutes');
        $this->assertContains('chat.example.org', collect((new MockNetworkProvider)->contentFilters())->firstWhere('id', 'sim-filter-students')['block'], 'Timed blocks work the same way');

        $this->travel(40)->minutes();
        $this->artisan('policy:push --boundaries')->assertSuccessful();
        $this->assertFalse($this->studentFilterAllows('exam-tool.example.org'), 'Closed at end plus 5 minutes');
    }

    public function test_it_can_approve_a_different_window_and_change_it_later(): void
    {
        $it = User::factory()->it()->create();
        $request = $this->pendingRequest(['film.example.org'], Reach::types('staff', 'student'));

        $this->actingAs($it)->post(route('it.requests.action', $request), [
            'action' => 'approve', 'access_from' => now()->addDays(2)->setTime(9, 0)->format('Y-m-d\TH:i'), 'access_until' => now()->addDays(2)->setTime(10, 0)->format('Y-m-d\TH:i'),
        ])->assertSessionHas('success');

        $request->refresh();
        $this->assertTrue($request->windowChanged());
        $this->assertEquals($request->expires_at, PolicyEntry::query()->where('website_request_id', $request->id)->value('expires_at'));
        Notification::assertSentTo($request->requester, RequestDecisionNotification::class, fn ($notification, $channels, $notifiable) => str_contains($notification->toMail($notifiable)->subject, 'approved and scheduled'));

        $this->actingAs($it)->post(route('it.requests.action', $request), ['action' => 'window', 'access_from' => '', 'access_until' => ''])->assertSessionHas('success');
        $this->assertNull($request->refresh()->expires_at);
        $this->assertSame(0, PolicyEntry::query()->where('website_request_id', $request->id)->whereNotNull('expires_at')->count(), 'Entries become permanent too');

        $this->actingAs($it)->post(route('it.requests.action', $request), ['action' => 'window', 'access_until' => now()->subHour()->format('Y-m-d\TH:i')])
            ->assertSessionHasErrors('access_until');
    }

    public function test_requesters_are_emailed_on_receipt_reminder_and_expiry(): void
    {
        $teacher = User::factory()->teacher()->create();
        $request = app(RequestWorkflow::class)->submit($teacher, [
            'domains' => ['course.example.org'], 'access_scope' => Reach::types('staff', 'student'), 'category' => 'Teaching and learning',
            'use_case' => 'Students need this resource for a supervised classroom activity.', 'requested_from' => null, 'requested_until' => now()->addDays(3),
        ]);
        Notification::assertSentTo($teacher, RequestDecisionNotification::class, fn ($notification, $channels, $notifiable) => str_contains($notification->toMail($notifiable)->subject, 'received'));

        app(RequestWorkflow::class)->approve($request, User::factory()->it()->create());
        $this->travel(49)->hours();
        $this->artisan('requests:remind')->assertSuccessful();
        $this->artisan('requests:remind')->assertSuccessful();
        Notification::assertSentToTimes($teacher, RequestDecisionNotification::class, 3);

        $this->travel(1)->day();
        $this->artisan('requests:expire')->assertSuccessful();
        $this->assertSame(RequestStatus::Expired, $request->refresh()->status);
        Notification::assertSentTo($teacher, RequestDecisionNotification::class, fn ($notification, $channels, $notifiable) => str_contains($notification->toMail($notifiable)->subject, 'access has ended'));
    }
}
