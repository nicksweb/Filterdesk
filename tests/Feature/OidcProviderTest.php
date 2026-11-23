<?php

namespace Tests\Feature;

use App\Models\IntegrationConfig;
use App\Models\User;
use App\Services\Auth\UserProvisioningService;
use App\Services\Oidc\OidcClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

/**
 * Single sign-on with providers other than Entra ID: a CloudWork-style
 * provider (no userinfo endpoint, client_secret_basic only, PKCE, custom
 * scope, roles in a "role" claim), and an Entra-style one (userinfo,
 * client_secret_post, groups).
 */
class OidcProviderTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    private const ISSUER = 'https://login.example-idp.test';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::flush();
        $this->installWithPolicies();
    }

    private function configure(array $discovery, array $configuration = []): void
    {
        Http::fake([
            self::ISSUER.'/.well-known/openid-configuration' => Http::response($discovery + [
                'issuer' => self::ISSUER,
                'authorization_endpoint' => self::ISSUER.'/oauth/authorize',
                'token_endpoint' => self::ISSUER.'/oauth/token',
            ]),
        ]);
        IntegrationConfig::query()->create(['provider' => 'oidc', 'name' => 'OpenID Connect', 'mode' => 'active', 'enabled' => true,
            'configuration' => $configuration + ['issuer' => self::ISSUER, 'client_id' => 'filterdesk', 'default_role' => 'teacher'],
            'credentials' => ['client_secret' => 's3cret']]);
    }

    private function jwt(array $claims): string
    {
        $part = fn (array $data) => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');

        return $part(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$part($claims).'.signature';
    }

    /** @return array{0: string, 1: array<string, string>} the authorization URL and its query */
    private function startSignIn(): array
    {
        $location = $this->get(route('auth.oidc'))->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return [$location, $query];
    }

    public function test_a_cloudwork_style_provider_signs_in_with_pkce_basic_auth_and_id_token_claims(): void
    {
        $this->configure([
            'token_endpoint_auth_methods_supported' => ['client_secret_basic'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => ['openid', 'email', 'profile', 'https://scopes.cloudwork.id/profile'],
        ], [
            'scopes' => ['https://scopes.cloudwork.id/profile'], 'group_claims' => ['role'],
            'role_rules' => UserProvisioningService::parseRules('group:staff=teacher'),
        ]);
        Http::fake([self::ISSUER.'/oauth/token' => Http::response(['access_token' => 'at', 'token_type' => 'Bearer', 'expires_in' => 3600,
            'id_token' => $this->jwt(['sub' => 'cw-123', 'email' => 'Teacher@School.Example', 'given_name' => 'Tess', 'family_name' => 'Teacher', 'role' => 'Staff'])])]);

        [$location, $query] = $this->startSignIn();
        $this->assertStringStartsWith(self::ISSUER.'/oauth/authorize', $location);
        $this->assertStringContainsString('https://scopes.cloudwork.id/profile', $query['scope']);
        $this->assertSame('S256', $query['code_challenge_method']);

        $this->get(route('auth.oidc.callback', ['code' => 'abc', 'state' => $query['state']]));
        $this->assertNull(Cache::get(OidcClient::LAST_ERROR_KEY), json_encode(Cache::get(OidcClient::LAST_ERROR_KEY)));

        $user = User::query()->where('oidc_subject', 'cw-123')->sole();
        $this->assertSame('teacher@school.example', $user->email);
        $this->assertSame('Tess Teacher', $user->name);
        $this->assertAuthenticatedAs($user);
        $claims = Cache::get(OidcClient::LAST_CLAIMS_KEY)['claims'];
        $this->assertSame('Staff', $claims['role'], 'Role values are shown for writing rules');
        $this->assertSame('(not shown)', $claims['email'], 'Personal values are not');
        Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/oauth/token')
            && str_starts_with((string) ($request->header('Authorization')[0] ?? ''), 'Basic ')
            && filled($request['code_verifier'] ?? null) && ! isset($request['client_secret']));
    }

    public function test_an_entra_style_provider_still_uses_userinfo_and_the_form_body(): void
    {
        $this->configure([
            'userinfo_endpoint' => self::ISSUER.'/oidc/userinfo',
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic'],
        ]);
        Http::fake([
            self::ISSUER.'/oauth/token' => Http::response(['access_token' => 'at', 'token_type' => 'Bearer', 'expires_in' => 3600, 'id_token' => $this->jwt(['sub' => 'e-1', 'groups' => ['it-staff']])]),
            self::ISSUER.'/oidc/userinfo' => Http::response(['sub' => 'e-1', 'email' => 'it@school.example', 'name' => 'IT Person']),
        ]);

        [, $query] = $this->startSignIn();
        $this->get(route('auth.oidc.callback', ['code' => 'abc', 'state' => $query['state']]))->assertRedirect(route('dashboard'));

        $this->assertSame('IT Person', User::query()->where('oidc_subject', 'e-1')->value('name'));
        Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/oauth/token') && ($request['client_secret'] ?? null) === 's3cret');
    }

    public function test_a_provider_shaped_like_cloudwork_id_signs_in(): void
    {
        // CloudWork ID's discovery: userinfo, client_secret_post and _basic, no PKCE, claims email/given_name/family_name/preferred_username.
        $this->configure([
            'userinfo_endpoint' => self::ISSUER.'/api/oauth/userinfo',
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic'],
            'scopes_supported' => ['openid', 'https://scopes.cloudwork.id/profile', 'offline', 'email', 'profile', 'offline_access'],
            'claims_supported' => ['email', 'given_name', 'family_name', 'preferred_username'],
        ]);
        Http::fake([
            self::ISSUER.'/oauth/token' => Http::response(['access_token' => 'at', 'token_type' => 'Bearer', 'expires_in' => 3600, 'id_token' => $this->jwt(['sub' => 'cw-9'])]),
            self::ISSUER.'/api/oauth/userinfo' => Http::response(['sub' => 'cw-9', 'email' => 'sam@school.example', 'given_name' => 'Sam', 'family_name' => 'Staff', 'preferred_username' => 'sstaff']),
        ]);

        [, $query] = $this->startSignIn();
        $this->assertArrayNotHasKey('code_challenge', $query, 'No PKCE when the provider does not advertise it');
        $this->get(route('auth.oidc.callback', ['code' => 'abc', 'state' => $query['state']]))->assertRedirect(route('dashboard'));

        $this->assertSame('Sam Staff', User::query()->where('oidc_subject', 'cw-9')->value('name'));
        Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/oauth/token') && ($request['client_secret'] ?? null) === 's3cret');
    }

    public function test_failures_are_shown_to_administrators_with_the_reason(): void
    {
        $this->configure(['token_endpoint_auth_methods_supported' => ['client_secret_basic']]);
        [, $query] = $this->startSignIn();

        $this->get(route('auth.oidc.callback', ['error' => 'access_denied', 'error_description' => 'Redirect URI not registered', 'state' => $query['state']]))
            ->assertRedirect(route('login'));

        $this->assertStringContainsString('Redirect URI not registered', Cache::get(OidcClient::LAST_ERROR_KEY)['message']);
        $this->actingAs(User::factory()->admin()->create())->get(route('admin.settings.show', 'authentication'))
            ->assertSee('Last sign-in failure')->assertSee('Redirect URI not registered')->assertSee('PKCE');
    }

    public function test_a_wrong_issuer_is_a_readable_error_not_a_server_error(): void
    {
        Http::fake(['*' => Http::response('Not found', 404)]);

        $this->actingAs(User::factory()->admin()->create())->put(route('admin.settings.update', 'authentication'), [
            'oidc_enabled' => '1', 'oidc_issuer' => 'https://wrong.example-idp.test/.well-known/openid-configuration', 'oidc_client_id' => 'x', 'oidc_client_secret' => 'y',
            'default_role' => 'teacher', 'emergency_local_login' => '1',
        ])->assertSessionHasErrors('oidc_issuer');
        $message = session('errors')->first('oidc_issuer');
        $this->assertStringContainsString('https://wrong.example-idp.test/.well-known/openid-configuration: it answered HTTP 404.', $message);
    }
}
