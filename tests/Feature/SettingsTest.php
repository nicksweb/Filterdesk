<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\SettingsController;
use App\Models\IntegrationConfig;
use App\Models\PolicyList;
use App\Models\User;
use App\Services\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installWithPolicies();
    }

    public function test_every_settings_section_renders_for_admins_only(): void
    {
        $admin = User::factory()->admin()->create();
        foreach (array_keys(SettingsController::SECTIONS) as $section) {
            $this->actingAs($admin)->get(route('admin.settings.show', $section))->assertOk();
        }
        $this->actingAs(User::factory()->it()->create())->get(route('admin.settings.show'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.audit'))->assertOk();
        $this->actingAs($admin)->get(route('admin.diagnostics'))->assertOk()->assertSee('Database');
    }

    public function test_network_secret_is_write_only_and_kept_when_blank(): void
    {
        $admin = User::factory()->admin()->create();
        $payload = ['provider' => 'unifi', 'controller_url' => 'https://unifi.example.test', 'mode' => 'read_only', 'entry_matching' => 'exact', 'verify_tls' => '1'];

        $this->actingAs($admin)->put(route('admin.settings.update', 'network'), $payload + ['api_key' => 'secret-key-123'])->assertRedirect();
        $this->actingAs($admin)->put(route('admin.settings.update', 'network'), $payload + ['api_key' => '', 'site_id' => 'abc'])->assertRedirect();

        $unifi = IntegrationConfig::query()->where('provider', 'unifi')->firstOrFail();
        $this->assertSame('secret-key-123', $unifi->credentials['api_key']);
        $this->assertSame('abc', $unifi->configuration['site_id']);
        $this->assertTrue($unifi->enabled);
        $this->assertFalse(IntegrationConfig::query()->where('provider', 'mock')->value('enabled'));
        $this->actingAs($admin)->get(route('admin.settings.show', 'network'))->assertDontSee('secret-key-123');
    }

    public function test_approval_settings_and_new_profile_audience_lists(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->put(route('admin.settings.update', 'approval'), [
            'auto_approve_staff' => '0', 'students_can_request' => '1', 'default_temporary_days' => 14, 'categories' => "Teaching\nResearch",
        ])->assertRedirect();
        $this->assertTrue(app(SettingsRepository::class)->get('approval.students_can_request'));

        $this->actingAs($admin)->put(route('admin.settings.update', 'profiles'), [
            'new' => ['name' => 'Guest Wi-Fi', 'key' => 'guest', 'audience' => 'guest', 'probe_url' => 'http://probe-guest:3000'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(PolicyList::query()->where('audience', 'guest')->exists());
    }

    public function test_health_endpoint_reports_version_without_authentication(): void
    {
        $this->getJson('/health')->assertOk()->assertJson(['status' => 'ok', 'version' => config('version.app')]);
    }
}
