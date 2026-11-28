<?php

namespace Tests\Feature;

use App\Console\Commands\SendPolicyDigest;
use App\Http\Controllers\EmailPreferenceController;
use App\Jobs\RestoreToPoint;
use App\Models\AuditEvent;
use App\Models\ConfigSnapshot;
use App\Models\FilterPolicy;
use App\Models\FirewallList;
use App\Models\IntegrationConfig;
use App\Models\PendingAlert;
use App\Models\User;
use App\Models\WebsiteRequest;
use App\Notifications\FilterSyncAlertNotification;
use App\Notifications\PolicyDigestNotification;
use App\Notifications\RequestDecisionNotification;
use App\Services\Network\MockNetworkProvider;
use App\Services\Policy\FirewallAllowList;
use App\Services\Policy\StateHistory;
use App\Services\Requests\RequestNotifier;
use App\Services\SettingsIndex;
use App\Services\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

/**
 * Settings search, the privacy link, fewer emails (one summary for IT, a
 * choice for each requester), what's new, the firewall list options on the
 * Protection page, requests for someone else, and restoring to a point in time.
 */
class FewerEmailsAndRestoreTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    private const REQUEST = ['domains' => 'maps.example.org', 'network_types' => ['staff'], 'category' => 'Teaching and learning',
        'use_case' => 'Geography mapping lesson for Year 9', 'access_duration' => 'permanent', 'standards_confirmed' => '1'];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::flush();
        $this->installWithPolicies('active');
        $this->admin = User::factory()->admin()->create();
        app(SettingsRepository::class)->set('notifications.it_address', 'it@example.test');
    }

    public function test_settings_are_searchable_and_the_privacy_link_is_shown_everywhere(): void
    {
        $titles = collect(app(SettingsIndex::class)->all())->pluck('title');
        $this->assertTrue($titles->contains('Privacy policy link'));
        $this->assertTrue($titles->contains('Addresses per UniFi IP list'));
        $this->assertTrue($titles->contains('Summary for IT'));
        $this->assertSame('Privacy policy link', app(SettingsIndex::class)->search('privacy link')[0]['title']);

        $this->actingAs($this->admin)->get(route('admin.settings.show', ['section' => 'email', 'q' => 'privacy']))->assertOk()
            ->assertSee('data-settings-index', false)->assertSee('Privacy policy link')
            ->assertSee(route('admin.settings.show', ['section' => 'general', 'find' => 'Privacy policy link']), false);
        $this->actingAs($this->admin)->get(route('admin.settings.show', ['section' => 'email', 'q' => 'zzzzqq']))->assertSee('No settings match');

        $this->actingAs($this->admin)->put(route('admin.settings.update', 'general'), [
            'school_name' => 'Example School', 'timezone' => 'Australia/Brisbane', 'app_url' => 'https://filterdesk.example.test',
            'privacy_url' => 'https://www.example.test/privacy', 'privacy_label' => 'Our privacy policy',
        ])->assertSessionHasNoErrors();
        auth()->logout();
        $this->get(route('login'))->assertOk()->assertSee('https://www.example.test/privacy', false)->assertSee('Our privacy policy');
        $this->actingAs(User::factory()->teacher()->create())->get(route('requests.create'))->assertOk()->assertSee('How this information is used:');
    }

    public function test_it_gets_one_weekly_summary_with_the_alerts_held_for_it(): void
    {
        $settings = app(SettingsRepository::class);
        $this->assertSame('weekly', SendPolicyDigest::frequency($settings));

        app(RequestNotifier::class)->filterSyncAlert(new FilterSyncAlertNotification([['policy' => 'Students', 'slug' => 'students', 'status' => 'drift', 'summary' => '2 entries added in UniFi.']]));
        Notification::assertNothingSent();
        $this->assertSame('Changed outside Filterdesk: Students. 2 entries added in UniFi.', PendingAlert::query()->sole()->line);

        $this->travelTo(Carbon::parse('next tuesday 08:00', $settings->timezone()));
        $this->artisan('policy:digest')->assertSuccessful();
        Notification::assertNothingSent();

        $this->travelTo(Carbon::parse('next monday 08:00', $settings->timezone()));
        $this->artisan('policy:digest')->assertSuccessful();
        Notification::assertSentOnDemand(PolicyDigestNotification::class, function (PolicyDigestNotification $notification): bool {
            $this->assertStringContainsString('Students. 2 entries added in UniFi.', implode("\n", $notification->toMail(new AnonymousNotifiable)->introLines));

            return true;
        });
        $this->assertNotNull(PendingAlert::query()->sole()->sent_at);

        // Each alert goes once.
        Notification::fake();
        $this->travel(7)->days();
        $this->artisan('policy:digest')->assertSuccessful();
        Notification::assertSentOnDemand(PolicyDigestNotification::class, fn (PolicyDigestNotification $notification) => $notification->alerts === []);

        $settings->set('notifications', ['it_address' => 'it@example.test', 'alerts' => 'now']);
        app(RequestNotifier::class)->filterSyncAlert(new FilterSyncAlertNotification([['policy' => 'Staff', 'slug' => 'staff', 'status' => 'error', 'summary' => '']]));
        Notification::assertSentOnDemand(FilterSyncAlertNotification::class);

        $this->actingAs($this->admin)->put(route('admin.settings.update', 'email'), [
            'it_notification_address' => 'it@example.test', 'summary' => 'daily', 'summary_day' => 3, 'alerts' => 'summary', 'digest_time' => '07:00',
        ])->assertSessionHasNoErrors();
        $this->assertSame('daily', $settings->get('notifications.summary'));
        $this->assertSame('summary', RequestNotifier::alertsMode($settings));
    }

    public function test_a_requester_can_choose_only_the_emails_that_matter(): void
    {
        $teacher = User::factory()->teacher()->create();
        $this->actingAs($teacher)->post(route('requests.store'), self::REQUEST);
        $request = WebsiteRequest::query()->sole();
        auth()->logout();
        Notification::assertSentTo($teacher, RequestDecisionNotification::class, function (RequestDecisionNotification $notification) use ($teacher): bool {
            $this->assertStringContainsString('/email/preferences/'.$teacher->id, implode("\n", $notification->toMail($teacher)->outroLines));

            return true;
        });

        $this->get(route('email.preferences', $teacher))->assertForbidden();
        $link = EmailPreferenceController::link($teacher);
        $this->get($link)->assertOk()->assertSee('Only what matters');
        $this->assertSame('all', $teacher->refresh()->email_updates);
        $this->put($link, ['email_updates' => 'essential'])->assertRedirect();
        $this->assertSame('essential', $teacher->refresh()->email_updates);
        $this->assertTrue(AuditEvent::query()->where('action', 'user.email_updates_changed')->exists());

        Notification::fake();
        app(RequestNotifier::class)->received($request->refresh());
        app(RequestNotifier::class)->expired($request);
        Notification::assertNothingSent();

        $this->actingAs($teacher)->put(route('profile.update'), ['email_updates' => 'all'])->assertSessionHas('success');
        $this->assertSame('all', $teacher->refresh()->email_updates);
    }

    public function test_admins_see_whats_new_once_per_release(): void
    {
        config(['version.app' => '2026.12.33']);
        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->assertSee('new in Filterdesk')->assertSee('Read the release notes')
            ->assertSee('releases/tag/v2026.12.33', false)->assertSee('Search all settings');
        $this->actingAs(User::factory()->it()->create())->get(route('dashboard'))->assertDontSee('new in Filterdesk');

        $this->actingAs($this->admin)->post(route('whats-new.dismiss'))->assertRedirect();
        $this->actingAs($this->admin)->get(route('dashboard'))->assertDontSee('new in Filterdesk');
    }

    public function test_the_ip_list_options_are_on_the_protection_page(): void
    {
        $this->actingAs($this->admin)->get(route('admin.policy.blocklists.index'))->assertOk()->assertSee('Firewall list options')->assertSee('Test larger UniFi IP lists');
        $this->actingAs($this->admin)->put(route('admin.settings.firewall-lists'), ['max_ip_group_entries' => 50000, 'firewall_rules' => 'lists_only'])->assertSessionHas('success');
        $configuration = IntegrationConfig::query()->where('provider', 'mock')->sole()->configuration;
        $this->assertSame(50000, $configuration['max_ip_group_entries']);
        $this->assertSame('lists_only', $configuration['firewall_rules']);
        $this->assertTrue(AuditEvent::query()->where('action', 'settings.ip_list_size_changed')->exists());
        $this->actingAs($this->admin)->put(route('admin.settings.firewall-lists'), ['max_ip_group_entries' => 500000, 'firewall_rules' => 'manage'])->assertSessionHasErrors('max_ip_group_entries');
    }

    public function test_an_admin_submits_a_request_for_someone_who_has_never_signed_in(): void
    {
        $this->actingAs($this->admin)->post(route('admin.users.on-behalf'), ['email' => 'New.Teacher@Example.test', 'name' => '', 'role' => 'teacher'])
            ->assertRedirect(route('requests.create'));
        $person = User::query()->where('email', 'new.teacher@example.test')->sole();
        $this->assertSame('New Teacher', $person->name);
        $this->assertFalse($person->password_login_enabled);
        $this->assertAuthenticatedAs($person);

        $this->post(route('requests.store'), self::REQUEST);
        $request = WebsiteRequest::query()->latest('id')->first();
        $this->assertNotNull($request);
        $this->assertTrue($request->requester->is($person));
        $this->assertTrue($request->submittedBy->is($this->admin));

        $this->post(route('impersonation.stop'));
        $this->actingAs($this->admin)->get(route('requests.show', $request))->assertSee('sent for them by '.$this->admin->name);
        $this->actingAs($this->admin)->post(route('admin.users.on-behalf'), ['email' => $this->admin->email, 'role' => 'teacher'])->assertSessionHas('error');
    }

    public function test_content_filters_and_allow_lists_are_restored_to_a_point_in_time(): void
    {
        $history = app(StateHistory::class);
        $mock = new MockNetworkProvider;
        $students = FilterPolicy::query()->where('audience', 'student')->firstOrFail();
        $students->update(['provider_reference' => 'sim-filter-students']);
        $list = FirewallList::query()->create(['name' => 'Partners', 'family' => 'ipv4', 'kind' => 'allow', 'all_zones' => true, 'max_entries' => 2000]);
        app(FirewallAllowList::class)->add($list, ['203.0.113.10'], $this->admin);

        $mock->writeContentFilterLists('sim-filter-students', ['good.example.org'], ['bad.example.org']);
        $this->artisan('policy:history')->assertSuccessful();
        $this->assertSame(1, ConfigSnapshot::query()->where('reference', 'sim-filter-students')->count());
        $this->artisan('policy:history')->assertSuccessful();
        $this->assertSame(1, ConfigSnapshot::query()->where('reference', 'sim-filter-students')->count(), 'nothing changed, so no new version');
        $then = now()->addMinute();

        $this->travel(1)->hour();
        $mock->writeContentFilterLists('sim-filter-students', [], ['bad.example.org', 'google.com', 'new.example.org']);
        app(FirewallAllowList::class)->add($list->refresh(), ['198.51.100.7'], $this->admin);
        $this->artisan('policy:history')->assertSuccessful();

        $at = $then->clone()->setTimezone(app(SettingsRepository::class)->timezone())->format('Y-m-d\TH:i');
        $page = $this->actingAs($this->admin)->get(route('admin.restore.index', ['at' => $at]))->assertOk()
            ->assertSee('Students')->assertSee('allow +1 / −0, block +0 / −2')->assertSee('Partners')->assertSee('entries +0 / −1');
        $ids = $history->at($then)->pluck('id')->all();
        $this->assertCount(3, $ids, 'the Students and Staff filters and the allow list, one version each');

        $this->actingAs($this->admin)->post(route('admin.restore.store'), ['at' => $at, 'snapshots' => $ids])->assertSessionHasErrors('understood');
        $this->actingAs($this->admin)->post(route('admin.restore.store'), ['at' => $at, 'snapshots' => [...$ids, 999999], 'understood' => '1'])->assertSessionHas('success');

        $filter = collect($mock->contentFilters())->firstWhere('id', 'sim-filter-students');
        $this->assertSame(['good.example.org'], $filter['allow']);
        $this->assertSame(['bad.example.org'], $filter['block']);
        $this->assertSame(['203.0.113.10'], collect($list->refresh()->allow_entries)->pluck('value')->all());
        $this->assertTrue(AuditEvent::query()->where('action', 'policy.controller_restored')->exists());
        $this->assertSame('done', Cache::get(RestoreToPoint::STATUS_KEY)['state']);
        $this->actingAs($this->admin)->get(route('admin.restore.index'))->assertSee('Last restore');

        $this->actingAs(User::factory()->it()->create())->get(route('admin.restore.index'))->assertForbidden();
    }
}
