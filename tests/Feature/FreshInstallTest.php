<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Http\Controllers\Admin\SettingsController;
use App\Models\FilterPolicy;
use App\Models\IntegrationConfig;
use App\Models\NetworkProfile;
use App\Models\NetworkType;
use App\Models\PolicyEntry;
use App\Models\PolicyRelease;
use App\Models\User;
use App\Models\WebsiteRequest;
use App\Services\Approval\TwoPersonRule;
use App\Services\Policy\ControllerSync;
use App\Services\Policy\ProbePlanner;
use App\Services\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A brand-new install through every wizard step, then the features added
 * since the wizard was written, used on what it produced: the wizard must
 * keep up with the rest of the system.
 */
class FreshInstallTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::flush();
        Http::fake(['*' => Http::failedConnection()]);
    }

    private function install(array $approval = [], array $networks = []): void
    {
        $steps = [
            1 => [],
            2 => ['school_name' => 'Example College', 'timezone' => 'Australia/Brisbane', 'app_url' => 'https://filterdesk.example.test'],
            3 => ['admin_name' => 'Ada Admin', 'admin_email' => 'admin@example.test', 'admin_password' => self::PASSWORD, 'admin_password_confirmation' => self::PASSWORD,
                'emergency_local_login' => '1', 'oidc_enabled' => '0', 'default_role' => 'teacher'],
            4 => ['unifi_enabled' => '0', 'unifi_mode' => 'read_only'],
            5 => ['student_probe_url' => 'http://probe-student:3000', 'staff_probe_url' => 'http://probe-staff:3000', 'networks' => $networks],
            6 => ['it_notification_address' => 'it@example.test', 'mail_encryption' => 'none'],
            7 => $approval + ['auto_approve_staff' => '0', 'students_can_request' => '0', 'default_temporary_days' => 30, 'categories' => 'Teaching and learning, Research'],
            8 => [],
        ];
        foreach ($steps as $step => $fields) {
            $this->get(route('install.show', $step))->assertOk();
            $response = $this->post(route('install.store', $step), $fields);
            $response->assertSessionHasNoErrors();
        }
        $response->assertRedirect(route('login'));
        $this->get('/install/1')->assertNotFound();
    }

    public function test_a_fresh_install_runs_every_feature(): void
    {
        $this->install();
        $settings = app(SettingsRepository::class);

        // What the wizard set up.
        $this->assertSame(config('version.app'), $settings->get('installation.version'));
        $this->assertTrue((bool) $settings->get('approval.two_person_site_wide'));
        $this->assertTrue((bool) $settings->get('approval.two_person_override'));
        $this->assertSame(TwoPersonRule::DEFAULT_SENSITIVE, $settings->get('approval.sensitive_categories'));
        $this->assertSame(12, $settings->get('governance.review_months'));
        $this->assertSame(['staff', 'student'], FilterPolicy::query()->orderBy('audience')->pluck('audience')->all());
        $this->assertNotNull(NetworkType::byKey('staff'));
        $this->assertSame('mock', IntegrationConfig::query()->where('enabled', true)->whereIn('provider', ['mock', 'unifi'])->value('provider'));

        // The administrator signs in and sees what to finish.
        $this->post(route('auth.local'), ['email' => 'admin@example.test', 'password' => self::PASSWORD])->assertRedirect();
        $admin = User::query()->where('email', 'admin@example.test')->sole();
        $this->assertAuthenticatedAs($admin);
        $this->get(route('dashboard'))->assertOk()->assertSee('Finish setting up')->assertSee('Add a second IT officer')->assertSee('Enter the school calendar');

        // Every page loads on a fresh install.
        foreach ([
            route('requests.index'), route('requests.create'), route('checks.create'), route('reviews.index'), route('why'),
            route('admin.policy.index'), route('admin.policy.networks.index'), route('admin.policy.explorer', ['site' => 'example.org']),
            route('admin.policy.types.show', 'staff'), route('admin.policy.changes.index'), route('admin.policy.blocklists.index'), route('admin.policy.setup.show'),
            route('admin.insights'), route('admin.health'), route('admin.users.index'), route('admin.audit'), route('admin.diagnostics'),
            ...array_map(fn (string $section) => route('admin.settings.show', $section), array_keys(SettingsController::SECTIONS)),
        ] as $page) {
            $status = $this->get($page)->status();
            $this->assertLessThan(400, $status, "{$page} returned {$status} on a fresh install");
        }

        // A second IT officer, the calendar and a request from submission to a published version.
        $second = User::factory()->it()->create(['name' => 'Second Officer']);
        $this->put(route('admin.settings.update', 'calendar'), ['new_period' => ['name' => 'Term 1', 'kind' => 'term', 'start_date' => now()->toDateString(), 'end_date' => now()->addMonths(2)->toDateString()]])->assertSessionHas('success');
        $teacher = User::factory()->teacher()->create();
        $this->actingAs($teacher)->post(route('requests.store'), [
            'domains' => 'everyone.example.org', 'network_types' => ['staff', 'student'], 'category' => 'Teaching and learning',
            'use_case' => 'A whole-school reading programme', 'access_duration' => 'permanent', 'standards_confirmed' => '1',
        ])->assertSessionHasNoErrors();
        $request = WebsiteRequest::query()->sole();

        $this->actingAs($admin)->post(route('it.requests.action', $request), ['action' => 'approve', 'scope' => ['site']]);
        $this->assertSame(RequestStatus::AwaitingSecondApproval, $request->refresh()->status, 'Site-wide approval waits for a second officer');
        $this->actingAs($second)->post(route('it.requests.action', $request), ['action' => 'approve', 'scope' => ['site']]);
        $this->assertTrue($request->refresh()->status->isGranted());
        $this->assertNotNull(PolicyEntry::query()->where('domain', 'everyone.example.org')->value('review_at'), 'The new exception has a review date');

        // Publishing needs a linked filter in Active mode; on the simulation provider, link one.
        IntegrationConfig::query()->where('provider', 'mock')->update(['mode' => 'active']);
        FilterPolicy::query()->where('audience', 'student')->update(['provider_reference' => 'sim-filter-students']);
        $this->actingAs($admin)->post(route('admin.policy.changes.publish'))->assertSessionHas('success');
        $this->assertSame(1, PolicyRelease::query()->value('number'));

        foreach (['policy:push', 'policy:push --boundaries', 'policy:check-sync', 'blocklists:refresh', 'probes:check', 'exceptions:review', 'requests:expire', 'policy:digest'] as $command) {
            $this->assertContains($this->artisan($command)->run(), [0, 1], "{$command} crashed on a fresh install");
        }
    }

    public function test_the_wizard_saves_the_approval_choices(): void
    {
        $this->install(['two_person_site_wide' => '0', 'two_person_override' => '0', 'sensitive_categories' => "Gambling\nWeapons", 'review_months' => 6, 'grace_days' => 7]);
        $settings = app(SettingsRepository::class);

        $this->assertFalse((bool) $settings->get('approval.two_person_site_wide'));
        $this->assertFalse((bool) $settings->get('approval.two_person_override'));
        $this->assertSame(['Gambling', 'Weapons'], $settings->get('approval.sensitive_categories'));
        $this->assertSame(6, PolicyEntry::reviewMonths());
        $this->assertSame(7, PolicyEntry::graceDays());
    }

    public function test_extra_networks_added_in_the_wizard_get_profiles_types_policies_and_probes(): void
    {
        $this->install([], [
            ['name' => 'Guest Wi-Fi', 'type' => 'guest', 'vlan' => 80],
            ['name' => 'CCTV', 'type' => '__new', 'new_type' => 'CCTV', 'vlan' => 30],
            ['name' => '', 'type' => 'other', 'vlan' => ''],
        ]);

        $guest = NetworkProfile::query()->where('name', 'Guest Wi-Fi')->sole();
        $this->assertSame('guest', $guest->audience);
        $this->assertFalse($guest->enabled, 'Switched on once its probe container runs');
        $this->assertSame('http://probe-guest-wi-fi:3000', $guest->probe_url);
        $this->assertSame('80', $guest->unifi_network_id);

        $cctv = NetworkProfile::query()->where('name', 'CCTV')->sole();
        $this->assertSame('cctv', $cctv->audience);
        $this->assertSame('CCTV', NetworkType::byKey('cctv')?->name);
        $this->assertSame(4, NetworkProfile::query()->count(), 'Students, Staff and the two filled rows');
        $this->assertEqualsCanonicalizing(['staff', 'student', 'guest', 'cctv'], FilterPolicy::query()->pluck('audience')->all(), 'A filter policy per network type');

        // Once UniFi is read, VLAN 80 is matched to its network and the probe is planned there.
        app(ControllerSync::class)->state(true);
        $plan = app(ProbePlanner::class)->plan()->firstWhere('key', 'guest_wi_fi');
        $this->assertNotNull($plan, 'The guest network gets its own probe');
        $this->assertSame('probe-guest-wi-fi', $plan['service']);
        $this->assertSame($guest->refresh()->probe_url, $plan['probe_url']);
    }
}
