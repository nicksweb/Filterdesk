<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Models\WebsiteRequest;
use App\Services\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

class TimezoneAndSettingsTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    public function test_settings_survive_the_cache_as_plain_values(): void
    {
        $settings = app(SettingsRepository::class);
        $settings->set('school', ['name' => 'Example College', 'timezone' => 'Australia/Brisbane']);
        $settings->set('auth.emergency_local_login', false);

        $fresh = new SettingsRepository;
        $this->assertSame('Example College', $fresh->get('school.name'));
        $this->assertSame(['name' => 'Example College', 'timezone' => 'Australia/Brisbane'], $fresh->get('school'));
        $this->assertFalse($fresh->get('auth.emergency_local_login', true));
        $this->assertSame('Australia/Brisbane', (new SettingsRepository)->timezone());
    }

    public function test_temporary_windows_are_entered_in_school_time_and_stored_in_utc(): void
    {
        Notification::fake();
        $this->installWithPolicies();
        Setting::query()->create(['key' => 'school', 'value' => ['name' => 'Example', 'timezone' => 'Australia/Brisbane']]);
        app(SettingsRepository::class)->forget();
        $this->travelTo(now('UTC')->setDate(2026, 11, 19)->setTime(0, 0));

        $this->actingAs(User::factory()->teacher()->create())->post(route('requests.store'), [
            'domains' => 'exam.example.org', 'access_scope' => 'staff_students', 'category' => 'Teaching and learning',
            'use_case' => 'Students need this for a supervised examination session.', 'standards_confirmed' => '1',
            'access_duration' => 'temporary', 'requested_from' => '2026-11-20T09:00', 'requested_until' => '2026-11-20T11:00',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $request = WebsiteRequest::query()->firstOrFail();
        $this->assertSame('2026-11-19 23:00:00', $request->getRawOriginal('requested_from'));
        $this->assertSame('2026-11-20 01:00:00', $request->getRawOriginal('requested_until'));
        $this->assertSame('20 Nov 2026 9:00 am', $request->requested_from->local()->format('j M Y g:i a'));
    }

    public function test_upgrade_converts_legacy_wall_clock_windows_once(): void
    {
        Notification::fake();
        $this->installWithPolicies();
        Setting::query()->create(['key' => 'school', 'value' => ['timezone' => 'Australia/Brisbane']]);
        $request = $this->pendingRequest(['legacy.example.org'], attributes: ['requested_until' => '2030-01-01 10:00:00']);

        $this->artisan('itrequests:upgrade', ['--skip-migrations' => true])->assertSuccessful();
        $this->artisan('itrequests:upgrade', ['--skip-migrations' => true])->assertSuccessful();

        $this->assertSame('2030-01-01 00:00:00', $request->refresh()->getRawOriginal('requested_until'));
    }
}
