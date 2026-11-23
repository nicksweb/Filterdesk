<?php

namespace Tests\Feature;

use App\Models\FilterPolicy;
use App\Models\IntegrationConfig;
use App\Models\NetworkAssignment;
use App\Models\NetworkProfile;
use App\Models\User;
use App\Models\WebsiteRequest;
use App\Notifications\FilterSyncAlertNotification;
use App\Services\Auth\UserProvisioningService;
use App\Services\Network\MockNetworkProvider;
use App\Services\Policy\BaselineGuard;
use App\Services\Policy\ControllerSync;
use App\Services\Policy\ProbePlanner;
use App\Services\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

/**
 * The guided setup flow (plain choices, probes without guessing the trunk),
 * baseline filtering for networks without a filter, and user corrections.
 */
class SetupFlowTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::flush();
        $this->installWithPolicies('active');
        $this->admin = User::factory()->admin()->create();
    }

    private function guestFilters(): array
    {
        return collect(app(MockNetworkProvider::class)->contentFilters())->filter(fn (array $filter) => in_array('sim-guest', $filter['network_ids'], true))->pluck('name')->all();
    }

    public function test_a_plain_choice_per_filter_links_the_default_policy_or_creates_one(): void
    {
        $student = FilterPolicy::query()->where('audience', 'student')->sole();

        $this->actingAs($this->admin)->post(route('admin.policy.setup.store'), ['filters' => [
            'sim-filter-students' => ['as' => 'type:student', 'import' => '1'],
            'sim-filter-staff' => ['as' => 'type:guest', 'import' => '0'],
        ]])->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame('sim-filter-students', $student->refresh()->provider_reference, 'The unused Students policy is reused');
        $guest = FilterPolicy::query()->where('provider_reference', 'sim-filter-staff')->sole();
        $this->assertSame('guest', $guest->audience, 'No unused Guest policy, so a new one');

        $this->actingAs($this->admin)->post(route('admin.policy.setup.store'), ['filters' => ['sim-filter-staff' => ['as' => 'rm -rf']]])->assertSessionHasErrors();
    }

    public function test_probes_wait_for_the_trunk_and_the_file_needs_nothing_in_env_but_compose_file(): void
    {
        $this->actingAs($this->admin)->post(route('admin.policy.setup.import-all'));

        $page = $this->actingAs($this->admin)->get(route('admin.policy.setup.show'))->assertOk();
        $page->assertSee('./bin/probe-interfaces')->assertSee('-o parent=&lt;trunk&gt;.20', false)->assertDontSee('eth0');
        $file = $this->actingAs($this->admin)->get(route('admin.policy.setup.compose'))->getContent();
        $this->assertStringContainsString('# NOT READY: set the trunk interface', $file);
        $this->assertStringContainsString('-o parent=<trunk>.20', $file, 'Fails if pasted as it is');

        $this->actingAs($this->admin)->post(route('admin.policy.setup.probes'), ['trunk' => 'ens18'])->assertSessionHas('success');
        $file = $this->actingAs($this->admin)->get(route('admin.policy.setup.compose'))->getContent();
        $this->assertStringNotContainsString('<trunk>', $file);
        $this->assertStringContainsString('#    COMPOSE_FILE=compose.yml:compose.vlan.yml', $file);
        $this->assertStringContainsString('docker network ls --filter driver=macvlan', $file);
        $this->assertStringNotContainsString(' -f ', $file, 'No -f in the start command');
        $this->assertNotNull(NetworkProfile::query()->where('key', 'student')->value('unifi_network_id'), 'Saving the probes links the profiles');

        // A network the host cannot reach is left without a probe.
        $this->actingAs($this->admin)->post(route('admin.policy.setup.probes'), ['trunk' => 'ens18', 'skip' => ['sim-staff']])->assertSessionHas('success');
        $file = $this->actingAs($this->admin)->get(route('admin.policy.setup.compose'))->getContent();
        $this->assertStringNotContainsString('probe-staff:', $file);
        $this->assertStringContainsString('probe-student:', $file);
        $this->actingAs($this->admin)->get(route('admin.policy.setup.show'))->assertOk()->assertSee('plan-skipped', false);

        $this->assertSame('COMPOSE_FILE=compose.yml:compose.proxy.yml:compose.vlan.yml', app(ProbePlanner::class)->composeFileLine(true));
        $this->artisan('probes:compose', ['--proxy' => 'yes'])->assertSuccessful()->expectsOutputToContain('compose.proxy.yml:compose.vlan.yml');
    }

    public function test_existing_profiles_can_be_saved_and_switched_on(): void
    {
        $profile = NetworkProfile::query()->where('key', 'student')->sole();

        $this->actingAs($this->admin)->put(route('admin.settings.update', 'profiles'), ['profiles' => [
            $profile->id => ['name' => 'Students', 'audience' => 'student', 'probe_url' => 'http://probe-student:3000', 'enabled' => '1', 'sort_order' => 1],
        ]])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertTrue($profile->refresh()->enabled);
        $this->assertSame('Students', $profile->name);
    }

    public function test_networks_without_a_filter_get_students_filtering_unless_allowed(): void
    {
        $this->actingAs($this->admin)->post(route('admin.policy.setup.import-all'));
        $guard = app(BaselineGuard::class);

        // First run: Guest Wi-Fi was already unfiltered, so an admin decides.
        $guard->enforce();
        $guest = NetworkAssignment::query()->where('provider_network_id', 'sim-guest')->sole();
        $this->assertTrue($guest->needsBaselineDecision());
        $this->assertSame([], $this->guestFilters(), 'Nothing filtered without a decision');
        $this->actingAs($this->admin)->get(route('admin.policy.networks.index'))->assertOk()->assertSee('Needs a decision');

        // Allowed with a reason: left alone at every check.
        $this->actingAs($this->admin)->put(route('admin.policy.networks.unfiltered', $guest), ['allow' => '1', 'reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->admin)->put(route('admin.policy.networks.unfiltered', $guest), ['allow' => '1', 'reason' => 'Visitors only'])->assertSessionHas('success');
        $this->artisan('policy:check-sync')->assertSuccessful();
        $this->assertSame([], $this->guestFilters());

        // No longer allowed: the next check gives it the Students filter, and IT hears about it.
        $this->actingAs($this->admin)->put(route('admin.policy.networks.unfiltered', $guest), ['allow' => '0'])->assertSessionHas('success');
        $this->artisan('policy:check-sync')->assertSuccessful()->expectsOutputToContain('Guest Wi-Fi had no content filter');
        $this->assertSame(['Students'], $this->guestFilters());
        $this->assertSame(FilterPolicy::query()->where('audience', 'student')->value('id'), $guest->refresh()->filter_policy_id);
    }

    public function test_baseline_filtering_only_reports_in_read_only_mode(): void
    {
        $this->actingAs($this->admin)->post(route('admin.policy.setup.import-all'));
        app(SettingsRepository::class)->set(BaselineGuard::STARTED_KEY, now()->toIso8601String());
        IntegrationConfig::query()->where('provider', 'mock')->update(['mode' => 'read_only']);
        app(ControllerSync::class)->forget();

        $result = app(BaselineGuard::class)->enforce();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Active mode', $result['message']);
        $this->assertSame([], $this->guestFilters());
    }

    public function test_an_admin_can_correct_a_name_and_it_survives_single_sign_on(): void
    {
        $user = User::factory()->teacher()->create(['name' => 'jsmith', 'auth_source' => 'oidc', 'oidc_subject' => 'abc', 'email' => 'j.smith@example.test']);
        $local = User::factory()->teacher()->create(['email' => 'old@example.test']);

        $this->actingAs($this->admin)->put(route('admin.users.update', $user), ['name' => 'Jane Smith', 'email' => 'other@example.test', 'role' => 'teacher', 'enabled' => '1'])->assertSessionHas('success');
        $this->assertSame('Jane Smith', $user->refresh()->name);
        $this->assertSame('j.smith@example.test', $user->email, 'Single sign-on owns the email');
        $this->assertTrue($user->name_locked);

        IntegrationConfig::query()->create(['provider' => 'oidc', 'name' => 'SSO', 'mode' => 'read_only', 'enabled' => true, 'configuration' => []]);
        app(UserProvisioningService::class)->provision(['sub' => 'abc', 'email' => 'j.smith@example.test', 'name' => 'jsmith']);
        $this->assertSame('Jane Smith', $user->refresh()->name);

        $this->actingAs($this->admin)->put(route('admin.users.update', $local), ['name' => $local->name, 'email' => 'New@Example.test', 'role' => 'teacher', 'enabled' => '1'])->assertSessionHas('success');
        $this->assertSame('new@example.test', $local->refresh()->email);
    }

    public function test_deleting_a_user_keeps_their_requests_and_protects_the_last_admin(): void
    {
        $request = $this->pendingRequest();
        $requester = $request->requester;

        $this->actingAs($this->admin)->delete(route('admin.users.destroy', $requester))->assertSessionHas('success');

        $this->assertModelMissing($requester);
        $this->assertTrue($request->refresh()->requester->isDeletedPlaceholder());
        $this->assertFalse($request->requester->enabled);
        $this->actingAs($this->admin)->get(route('admin.users.index'))->assertOk()->assertDontSee('deleted-user@filterdesk.invalid');
        $this->assertDatabaseHas('audit_events', ['action' => 'user.deleted']);

        $this->actingAs($this->admin)->delete(route('admin.users.destroy', $this->admin))->assertSessionHas('error');
        $this->assertModelExists($this->admin);
        $this->assertSame(1, WebsiteRequest::query()->count());
    }

    public function test_students_see_only_student_access_and_the_network_is_recognised_from_the_address(): void
    {
        NetworkProfile::query()->update(['enabled' => true]);
        app(ControllerSync::class)->state(true);
        $this->actingAs($this->admin)->post(route('admin.policy.setup.import-all'));
        Http::fake(['*/check-batch' => Http::response(['results' => [['ok' => true, 'status' => 'allowed', 'httpStatus' => 200, 'finalUrl' => 'https://example.com/', 'title' => 'Example', 'durationMs' => 10]]])]);

        $student = User::factory()->create(['role' => 'student']);
        $this->actingAs($student)->post(route('checks.store'), ['websites' => 'example.com'])->assertOk()
            ->assertSee('Student Access')->assertDontSee('Staff Access');
        Http::assertSentCount(1);

        $teacher = User::factory()->teacher()->create();
        $this->actingAs($teacher)->withServerVariables(['REMOTE_ADDR' => '10.0.20.57'])->post(route('checks.store'), ['websites' => 'example.com'])->assertOk()
            ->assertSee('Staff Access')->assertSee('You\'re on <strong>Student Access</strong> (VLAN 20), which gets <strong>Students filtering</strong>', false)
            ->assertSeeInOrder(['Your network', 'Staff network probe']);

        $this->actingAs($teacher)->withServerVariables(['REMOTE_ADDR' => '192.0.2.9'])->get(route('checks.create'))->assertOk()->assertDontSee('here-notice', false);
    }

    public function test_a_paused_filter_is_flagged_and_reported_once_but_never_overridden(): void
    {
        $this->actingAs($this->admin)->post(route('admin.policy.setup.import-all'));
        app(SettingsRepository::class)->set(BaselineGuard::STARTED_KEY, now()->toIso8601String());
        app(SettingsRepository::class)->set('notifications.it_address', 'it@example.test');
        $filters = app(MockNetworkProvider::class)->contentFilters();
        $filters[1]['enabled'] = false; // Staff, paused in UniFi
        Cache::forever('mock.content_filters', $filters);
        NetworkAssignment::query()->where('provider_network_id', 'sim-guest')->update(['unfiltered_reason' => 'Visitors', 'unfiltered_reviewed_at' => now()]);

        $this->artisan('policy:check-sync')->assertSuccessful()->expectsOutputToContain('Staff is paused in UniFi');
        $this->artisan('policy:check-sync')->assertSuccessful();
        Notification::assertSentOnDemandTimes(FilterSyncAlertNotification::class, 1);

        $staffFilters = collect(app(MockNetworkProvider::class)->contentFilters())->filter(fn (array $filter) => in_array('sim-staff', $filter['network_ids'], true))->pluck('name')->all();
        $this->assertSame(['Staff'], $staffFilters, 'A paused network is not moved to Students automatically');
        $this->actingAs($this->admin)->get(route('admin.policy.networks.index'))->assertOk()->assertSee('Staff is paused in UniFi')->assertSee('the filter is paused in UniFi');
    }
}
