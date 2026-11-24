<?php

namespace Tests\Feature;

use App\Http\Controllers\OidcTestController;
use App\Models\IntegrationConfig;
use App\Models\User;
use App\Services\Auth\UserProvisioningService;
use App\Services\Oidc\OidcProviderHints;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

/**
 * Single sign-on in the installer: the provider's extra scope is asked for
 * (CloudWork ID only sends groups with it), roles follow the rules at each
 * sign-in, and a Test sign-in shows what the provider sends and the role
 * the rules give, without creating anyone. Afterwards, the rules can be
 * applied to accounts made before they were right.
 */
class SsoSetupTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    private const ISSUER = 'https://school.cloudworkengine.net';

    private const PASSWORD = 'correct-horse-battery-staple';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::flush();
    }

    private function fakeProvider(array $idToken): void
    {
        $part = fn (array $data) => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');
        Http::fake([
            self::ISSUER.'/.well-known/openid-configuration' => Http::response([
                'issuer' => self::ISSUER, 'authorization_endpoint' => self::ISSUER.'/oauth/authorize', 'token_endpoint' => self::ISSUER.'/oauth/token',
                'token_endpoint_auth_methods_supported' => ['client_secret_basic'], 'code_challenge_methods_supported' => ['S256'],
                'scopes_supported' => ['openid', 'email', 'profile', OidcProviderHints::CLOUDWORK_SCOPE],
            ]),
            self::ISSUER.'/oauth/token' => Http::response(['access_token' => 'at', 'token_type' => 'Bearer', 'expires_in' => 3600,
                'id_token' => $part(['alg' => 'RS256']).'.'.$part($idToken).'.signature']),
        ]);
    }

    /** @return array<string, string> */
    private function signInStep(array $overrides = []): array
    {
        return $overrides + ['admin_name' => 'Ada Admin', 'admin_email' => 'admin@example.test', 'admin_password' => self::PASSWORD, 'admin_password_confirmation' => self::PASSWORD,
            'emergency_local_login' => '1', 'oidc_enabled' => '1', 'oidc_issuer' => self::ISSUER, 'oidc_client_id' => 'filterdesk', 'oidc_client_secret' => 's3cret',
            'role_rules' => "group:itstaff=admin\ngroup:admin-staff=teacher", 'default_role' => 'student', 'sync_roles' => '1'];
    }

    private function throughSchool(): void
    {
        $this->post(route('install.store', 1), []);
        $this->post(route('install.store', 2), ['school_name' => 'Example College', 'timezone' => 'Australia/Brisbane', 'app_url' => 'https://filterdesk.example.test'])->assertSessionHasNoErrors();
    }

    public function test_the_installer_asks_for_cloudworks_scope_and_roles_follow_the_rules(): void
    {
        $this->fakeProvider(['sub' => 'x', 'email' => 'x@example.test']);
        $this->throughSchool();
        $this->get(route('install.show', 3))->assertOk()->assertSee('Provider-specific options')->assertSee('Test sign-in')->assertSee('Re-apply rules at every sign-in');

        $this->post(route('install.store', 3), $this->signInStep())->assertSessionHasNoErrors()
            ->assertSessionHas('info', fn (string $message) => str_contains($message, OidcProviderHints::CLOUDWORK_SCOPE));
        foreach ([4 => ['unifi_enabled' => '0', 'unifi_mode' => 'read_only'], 5 => ['student_probe_url' => 'http://probe-student:3000', 'staff_probe_url' => 'http://probe-staff:3000'],
            6 => ['it_notification_address' => 'it@example.test', 'mail_encryption' => 'none'], 7 => ['default_temporary_days' => 30, 'categories' => 'Teaching'], 8 => []] as $step => $fields) {
            $this->post(route('install.store', $step), $fields)->assertSessionHasNoErrors();
        }

        $configuration = IntegrationConfig::query()->where('provider', 'oidc')->sole()->configuration;
        $this->assertSame([OidcProviderHints::CLOUDWORK_SCOPE], $configuration['scopes']);
        $this->assertTrue($configuration['sync_roles']);
    }

    public function test_test_sign_in_shows_the_groups_and_role_without_creating_anyone(): void
    {
        $this->fakeProvider(['sub' => 'cw-1', 'email' => 'it.person@example.test', 'given_name' => 'Ivy', 'https://cloudwork.id/claims/groups' => ['itstaff', 'all-staff']]);
        $this->throughSchool();

        $location = $this->post(route('install.oidc-test'), $this->signInStep(['oidc_scopes' => OidcProviderHints::CLOUDWORK_SCOPE]))->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith(self::ISSUER.'/oauth/authorize', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertStringContainsString(OidcProviderHints::CLOUDWORK_SCOPE, $query['scope']);

        $this->get(route('auth.oidc.callback', ['code' => 'abc', 'state' => $query['state']]))->assertRedirect(route('install.show', 3))
            ->assertSessionHas(OidcTestController::RESULT_KEY, fn (array $result) => $result['ok'] && $result['role'] === 'admin' && $result['rule'] === 'group:itstaff=admin');
        $this->assertSame(0, User::query()->count(), 'Nobody is created');
        $this->assertGuest();

        $this->get(route('install.show', 3))->assertOk()->assertSee('With these rules this account would be')->assertSee('matched group:itstaff=admin')
            ->assertSee('data-append-rule="group:all-staff=teacher"', false)->assertSee('(not shown)');
    }

    public function test_test_sign_in_warns_when_no_groups_arrive(): void
    {
        $this->fakeProvider(['sub' => 'cw-2', 'email' => 'someone@example.test']);
        $this->throughSchool();
        $location = $this->post(route('install.oidc-test'), $this->signInStep())->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->get(route('auth.oidc.callback', ['code' => 'abc', 'state' => $query['state']]))
            ->assertSessionHas(OidcTestController::RESULT_KEY, fn (array $result) => $result['role'] === 'student' && $result['rule'] === null
                && str_contains($result['warning'], 'No groups or roles came back') && str_contains($result['warning'], OidcProviderHints::CLOUDWORK_SCOPE));
    }

    public function test_the_rules_can_be_applied_to_accounts_made_before_they_were_right(): void
    {
        $this->fakeProvider(['sub' => 'cw-3', 'email' => 'x@example.test']);
        $this->installWithPolicies();
        IntegrationConfig::query()->create(['provider' => 'oidc', 'name' => 'OpenID Connect', 'mode' => 'active', 'enabled' => true, 'credentials' => ['client_secret' => 's3cret'],
            'configuration' => ['issuer' => self::ISSUER, 'client_id' => 'filterdesk', 'default_role' => 'student', 'sync_roles' => false, 'scopes' => [OidcProviderHints::CLOUDWORK_SCOPE],
                'role_rules' => UserProvisioningService::parseRules('group:itstaff=admin')]]);
        $admin = User::factory()->admin()->create();
        $wrong = User::factory()->create(['role' => 'student', 'auth_source' => 'oidc', 'sso_groups' => ['itstaff'], 'email' => 'ivy@example.test']);
        $right = User::factory()->create(['role' => 'student', 'auth_source' => 'oidc', 'sso_groups' => ['year-9'], 'email' => 'sam@example.test']);

        $this->actingAs($admin)->get(route('admin.settings.show', 'authentication'))->assertOk()
            ->assertSee('Accounts the rules would change')->assertSee('ivy@example.test')->assertDontSee('sam@example.test');
        $this->actingAs($admin)->post(route('admin.settings.apply-roles'))->assertSessionHas('success');
        $this->assertSame('admin', $wrong->refresh()->role);
        $this->assertSame('student', $right->refresh()->role);

        // Test sign-in from Settings keeps the admin signed in as themselves.
        $location = $this->actingAs($admin)->post(route('admin.settings.test-sign-in'))->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->get(route('auth.oidc.callback', ['code' => 'abc', 'state' => $query['state']]))->assertRedirect(route('admin.settings.show', 'authentication'));
        $this->assertAuthenticatedAs($admin);
        $this->assertSame(0, User::query()->where('email', 'x@example.test')->count());
    }
}
