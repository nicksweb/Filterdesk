<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\IntegrationConfig;
use App\Models\NetworkAssignment;
use App\Models\Setting;
use App\Models\User;
use App\Services\Network\MockNetworkProvider;
use App\Services\Policy\BaselineGuard;
use App\Services\Policy\ControllerLearner;
use App\Services\Policy\ControllerSync;
use App\Services\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

/**
 * Baseline filtering never fills a console with a filter by surprise: it
 * starts only once a Students policy is linked, starts again for another
 * console or site, filters new user networks but asks about device
 * networks, never uses a scheduled filter on its own, leaves paused
 * filters alone, and what it added can be taken off again. Also the
 * installer's UniFi step: test the connection and pick the console.
 */
class BaselineSafetyTest extends TestCase
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

    /** @return list<string> filters on a network */
    private function filtersOn(string $networkId): array
    {
        return collect(app(MockNetworkProvider::class)->contentFilters())->filter(fn (array $filter) => in_array($networkId, $filter['network_ids'], true))->pluck('name')->values()->all();
    }

    public function test_it_waits_for_a_linked_policy_and_starts_again_for_another_console(): void
    {
        // Before Guided setup: nothing is filtered and the guard has not started.
        $this->artisan('policy:check-sync')->assertSuccessful();
        $this->assertFalse(app(BaselineGuard::class)->started());
        $this->assertSame([], $this->filtersOn('sim-guest'));

        // Guided setup: unfiltered networks found then wait for a decision.
        $this->actingAs($this->admin)->post(route('admin.policy.setup.import-all'));
        $this->artisan('policy:check-sync')->assertSuccessful();
        $this->assertTrue(app(BaselineGuard::class)->started());
        $this->assertSame([], $this->filtersOn('sim-guest'));
        $this->assertSame(BaselineGuard::FOUND_AT_START, NetworkAssignment::query()->where('provider_network_id', 'sim-guest')->value('unfiltered_reason'));

        // Another console (or site): the guard starts again rather than treating every network as new.
        IntegrationConfig::query()->where('provider', 'mock')->update(['configuration' => ['site_id' => 'other-site']]);
        NetworkAssignment::query()->update(['unfiltered_reason' => null]);
        $this->assertFalse(app(BaselineGuard::class)->started());
        $this->artisan('policy:check-sync')->assertSuccessful();
        $this->assertSame([], $this->filtersOn('sim-guest'));
    }

    public function test_new_device_networks_are_asked_about_and_new_user_networks_are_filtered(): void
    {
        $this->actingAs($this->admin)->post(route('admin.policy.setup.import-all'));
        app(SettingsRepository::class)->set(BaselineGuard::STARTED_KEY, now()->toIso8601String());
        $guest = NetworkAssignment::query()->where('provider_network_id', 'sim-guest')->sole();
        $this->assertSame('device', app(ControllerLearner::class)->kindFor('ISP Uplink', false), 'ISP and WAN hand-offs are device networks');

        app(BaselineGuard::class)->enforce();
        $this->assertSame(['Students'], $this->filtersOn('sim-guest'), 'A user network is filtered');

        // What it added can be taken off again, and is not added back.
        $this->actingAs($this->admin)->get(route('admin.policy.networks.index'))->assertOk()->assertSee('Filterdesk added Students to 1 network')->assertSee('automatically')->assertSee('Take it off again');
        $event = AuditEvent::query()->where('action', 'policy.baseline_filtered')->latest('id')->firstOrFail();
        $this->actingAs($this->admin)->post(route('admin.policy.networks.baseline-undo', $event))->assertSessionHas('success');
        $this->assertSame([], $this->filtersOn('sim-guest'));
        $this->assertSame(BaselineGuard::UNDONE, $guest->refresh()->unfiltered_reason);
        $this->artisan('policy:check-sync')->assertSuccessful();
        $this->assertSame([], $this->filtersOn('sim-guest'), 'Waits for a decision instead');
        $this->actingAs($this->admin)->get(route('admin.policy.networks.index'))->assertOk()->assertDontSee('Take it off again')->assertSee('taken off again after automatic baseline filtering');
    }

    public function test_a_device_network_is_never_filtered_automatically(): void
    {
        $this->actingAs($this->admin)->post(route('admin.policy.setup.import-all'));
        app(SettingsRepository::class)->set(BaselineGuard::STARTED_KEY, now()->toIso8601String());
        NetworkAssignment::query()->where('provider_network_id', 'sim-guest')->update(['role' => 'device']);

        app(BaselineGuard::class)->enforce();
        $this->assertSame([], $this->filtersOn('sim-guest'));
        $this->assertSame(BaselineGuard::NEW_DEVICE_NETWORK, NetworkAssignment::query()->where('provider_network_id', 'sim-guest')->value('unfiltered_reason'));

        // An admin can still add it.
        $this->actingAs($this->admin)->post(route('admin.policy.networks.protect'), ['networks' => ['sim-guest']])->assertSessionHas('success');
        $this->assertSame(['Students'], $this->filtersOn('sim-guest'));
    }

    public function test_a_scheduled_students_filter_is_not_used_automatically(): void
    {
        $filters = app(MockNetworkProvider::class)->contentFilters();
        $filters[0]['schedule'] = 'EVERY_WEEK';
        Cache::forever('mock.content_filters', $filters);
        $this->actingAs($this->admin)->post(route('admin.policy.setup.import-all'));
        app(SettingsRepository::class)->set(BaselineGuard::STARTED_KEY, now()->toIso8601String());

        $result = app(BaselineGuard::class)->enforce();
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('is on a schedule', $result['message']);
        $this->assertSame([], $this->filtersOn('sim-guest'));
    }

    public function test_the_installer_tests_unifi_and_offers_the_consoles_to_pick_from(): void
    {
        Cache::flush();
        Setting::query()->where('key', 'installation.completed')->delete();
        Http::fake([
            'api.ui.com/v1/hosts*' => Http::response(['data' => [
                ['id' => 'AAAA:1', 'type' => 'console', 'reportedState' => ['name' => 'Main campus']],
                ['id' => 'BBBB:2', 'type' => 'console', 'reportedState' => ['name' => 'Junior school']],
            ]]),
            'api.ui.com/v1/connector/consoles/BBBB:2/*/sites*' => Http::response(['data' => [['id' => 'site-1', 'name' => 'Default']]]),
            'api.ui.com/v1/connector/consoles/BBBB:2/*/networks*' => Http::response(['data' => [['id' => 'n1', 'name' => 'Students', 'vlanId' => 20]]]),
            'api.ui.com/v1/connector/consoles/BBBB:2/*' => Http::response(['data' => []]),
        ]);
        $this->post(route('install.store', 1), []);
        $this->post(route('install.store', 2), ['school_name' => 'Example College', 'timezone' => 'Australia/Brisbane', 'app_url' => 'https://filterdesk.example.test']);

        $this->post(route('install.unifi-test'), ['unifi_connection' => 'cloud', 'unifi_api_key' => 'site-manager-key', 'unifi_mode' => 'read_only'])->assertRedirect(route('install.show', 4));
        $this->get(route('install.show', 4))->assertOk()->assertSee('Console to manage')->assertSee('Main campus')->assertSee('Junior school')->assertSee('value="BBBB:2"', false);

        $this->post(route('install.unifi-test'), ['unifi_connection' => 'cloud', 'unifi_console_id' => 'BBBB:2', 'unifi_mode' => 'read_only'])->assertRedirect(route('install.show', 4));
        $this->assertSame('BBBB:2', session('installation')['unifi_console_id']);
        $this->assertSame('site-manager-key', session('installation')['unifi_api_key'], 'The key entered first is kept');
        $this->assertArrayHasKey('unifi_test', session('installation'));
    }

    public function test_an_add_button_names_the_filter_and_warns_on_device_networks_and_its_additions_can_be_undone(): void
    {
        $this->actingAs($this->admin)->post(route('admin.policy.setup.import-all'));
        NetworkAssignment::query()->where('provider_network_id', 'sim-guest')->update(['role' => 'device', 'unfiltered_reason' => BaselineGuard::FOUND_AT_START]);
        app(SettingsRepository::class)->set(BaselineGuard::STARTED_KEY, now()->toIso8601String());

        $this->actingAs($this->admin)->get(route('admin.policy.networks.index'))->assertOk()
            ->assertSee('data-confirm-detail="Adds the UniFi filter Students (Always; 1 blocked categories)', false)->assertSee('This is a device network');

        $this->actingAs($this->admin)->post(route('admin.policy.networks.protect'), ['networks' => ['sim-guest']])->assertSessionHas('success');
        $this->actingAs($this->admin)->get(route('admin.policy.networks.index'))->assertOk()
            ->assertSee('Filterdesk added Students to 1 network')->assertSee('by '.$this->admin->name)->assertSee('added-by', false);
        $event = AuditEvent::query()->where('action', 'policy.baseline_protected')->sole();
        $this->actingAs($this->admin)->post(route('admin.policy.networks.baseline-undo', $event))->assertSessionHas('success');
        $this->assertSame([], $this->filtersOn('sim-guest'));
    }

    public function test_networks_found_while_read_only_wait_for_a_decision_instead_of_all_being_filtered_later(): void
    {
        $this->actingAs($this->admin)->post(route('admin.policy.setup.import-all'));
        app(SettingsRepository::class)->set(BaselineGuard::STARTED_KEY, now()->toIso8601String());
        IntegrationConfig::query()->where('provider', 'mock')->update(['mode' => 'read_only']);
        app(ControllerSync::class)->forget();

        $this->assertStringContainsString("isn't in Active mode", app(BaselineGuard::class)->enforce()['message']);
        $this->assertSame(BaselineGuard::FOUND_READ_ONLY, NetworkAssignment::query()->where('provider_network_id', 'sim-guest')->value('unfiltered_reason'));

        IntegrationConfig::query()->where('provider', 'mock')->update(['mode' => 'active']);
        app(BaselineGuard::class)->enforce();
        $this->assertSame([], $this->filtersOn('sim-guest'), 'Switching Active mode on does not filter them all at once');
    }
}
