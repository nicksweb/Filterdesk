<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\User;
use App\Notifications\AccountLockedNotification;
use App\Notifications\FilterSyncAlertNotification;
use App\Notifications\RequestDecisionNotification;
use App\Services\Notifications\CommsHours;
use App\Services\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

/** Emails go Monday to Friday, 7 am to 5 pm by default; anything outside waits for the next opening. */
class CommsHoursTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::flush();
        $this->installWithPolicies('active');
    }

    private function at(string $time): Carbon
    {
        return Carbon::parse($time, app(SettingsRepository::class)->timezone());
    }

    public function test_emails_wait_for_weekday_working_hours_by_default(): void
    {
        $hours = app(CommsHours::class);
        $this->assertSame('Monday to Friday, 7:00 am to 5:00 pm', $hours->describe());

        // Wednesday 2 December 2026.
        $this->assertNull($hours->delayUntil($this->at('2026-12-02 10:15')));
        $this->assertTrue($hours->delayUntil($this->at('2026-12-02 06:30'))->equalTo($this->at('2026-12-02 07:00')));
        $this->assertTrue($hours->delayUntil($this->at('2026-12-02 17:00'))->equalTo($this->at('2026-12-03 07:00')));
        // Friday evening and the weekend wait for Monday morning.
        $this->assertTrue($hours->delayUntil($this->at('2026-12-04 18:00'))->equalTo($this->at('2026-12-07 07:00')));
        $this->assertTrue($hours->delayUntil($this->at('2026-12-05 11:00'))->equalTo($this->at('2026-12-07 07:00')));

        $this->travelTo($this->at('2026-12-05 11:00'));
        $alert = new FilterSyncAlertNotification([]);
        $this->assertTrue($alert->withDelay(new User, 'mail')->equalTo($this->at('2026-12-07 07:00')));
        $this->assertFalse(method_exists(AccountLockedNotification::class, 'withDelay'), 'sign-in alerts are never held');

        // An "ends soon" email held until Monday isn't sent if the access ended meanwhile.
        $request = $this->pendingRequest(['games.example.org'], null, ['requester_id' => User::factory()->teacher()->create()->id, 'status' => RequestStatus::Active, 'expires_at' => now()->addHours(5)]);
        $ending = new RequestDecisionNotification($request, 'Ends soon', 'ending');
        $this->assertTrue($ending->shouldSend($request->requester, 'mail'));
        $this->travelTo($this->at('2026-12-07 07:00'));
        $this->assertFalse((new RequestDecisionNotification($request->refresh(), 'Ends soon', 'ending'))->shouldSend($request->requester, 'mail'));

        app(SettingsRepository::class)->set('notifications', ['hours' => ['enabled' => false]]);
        $this->assertNull(app(CommsHours::class)->delayUntil());
    }

    public function test_admins_set_the_days_and_hours(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('admin.settings.show', 'email'))->assertOk()->assertSee('When emails are sent')->assertSee('Monday to Friday, 7:00 am to 5:00 pm');

        $form = ['it_notification_address' => 'it@example.test', 'digest_time' => '07:30', 'hours_enabled' => '1', 'hours_days' => [1, 2, 3, 4, 5, 6], 'hours_from' => '08:00', 'hours_to' => '16:30'];
        $this->actingAs($admin)->put(route('admin.settings.update', 'email'), $form)->assertSessionHasNoErrors();
        $this->assertSame('Monday, Tuesday, Wednesday, Thursday, Friday, Saturday, 8:00 am to 4:30 pm', app(CommsHours::class)->describe());
        $this->assertNull(app(CommsHours::class)->delayUntil($this->at('2026-12-05 09:00')));

        $this->actingAs($admin)->put(route('admin.settings.update', 'email'), ['hours_to' => '07:00'] + $form)->assertSessionHasErrors('hours_to');
    }
}
