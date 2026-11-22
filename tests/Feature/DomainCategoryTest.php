<?php

namespace Tests\Feature;

use App\Models\FilterPolicy;
use App\Models\IntegrationConfig;
use App\Models\User;
use App\Models\WebsiteRequest;
use App\Services\Domains\DomainCategorizer;
use App\Services\Network\MockNetworkProvider;
use App\Services\Policy\ControllerSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

class DomainCategoryTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::flush();
        $this->installWithPolicies('active');
    }

    private function enable(string $source = 'radar'): void
    {
        IntegrationConfig::query()->create(['provider' => DomainCategorizer::PROVIDER, 'name' => 'Website categories', 'mode' => 'read_only', 'enabled' => true,
            'configuration' => ['source' => $source, 'account_id' => $source === 'intel' ? str_repeat('a', 32) : null], 'credentials' => ['token' => 'test-token']]);
    }

    public function test_it_is_off_until_enabled_and_sends_nothing(): void
    {
        Http::fake();
        $this->assertSame([], app(DomainCategorizer::class)->many(['casino.example']));
        Http::assertNothingSent();
    }

    public function test_requests_record_categories_and_show_the_matching_unifi_category_and_who_blocks_it(): void
    {
        $this->enable();
        Http::fake(['api.cloudflare.com/client/v4/radar/ranking/domain/*' => Http::response(['success' => true, 'result' => ['details_0' => ['categories' => [
            ['id' => 99, 'name' => 'Gambling', 'superCategoryId' => 7],
        ]]]])]);
        FilterPolicy::query()->where('audience', 'student')->update(['provider_reference' => 'sim-filter-students']);
        (new MockNetworkProvider)->writeContentFilterCategories('sim-filter-students', ['ADULT', 'GAMBLING']);
        app(ControllerSync::class)->state(true);

        $teacher = User::factory()->teacher()->create();
        $this->actingAs($teacher)->get(route('requests.create', ['domains' => 'casino.example']))->assertOk()->assertSee('casino.example is')->assertSee('Gambling');
        $this->actingAs($teacher)->post(route('requests.store'), [
            'request_type' => 'allow', 'domains' => 'casino.example', 'network_types' => ['staff'], 'category' => 'Teaching and learning',
            'use_case' => 'Studying probability in maths class.', 'standards_confirmed' => '1', 'access_duration' => 'permanent',
        ])->assertRedirect();

        $request = WebsiteRequest::query()->sole();
        $this->assertSame(['Gambling'], $request->domain_categories['casino.example']['categories']);
        $this->assertSame('GAMBLING', $request->domain_categories['casino.example']['unifi'][0]['id']);
        $this->assertSame(['Students'], $request->domain_categories['casino.example']['unifi'][0]['blocked_by']);

        $this->actingAs(User::factory()->it()->create())->get(route('requests.show', $request))->assertOk()
            ->assertSee('What the sites are')->assertSee('Gambling (blocked by Students)');
        Http::assertSent(fn ($sent) => $sent->hasHeader('Authorization', 'Bearer test-token'));
        Http::assertSentCount(1);
    }

    public function test_intel_source_and_a_refused_token(): void
    {
        $this->enable('intel');
        Http::fake(['api.cloudflare.com/client/v4/accounts/*/intel/domain*' => Http::sequence()
            ->push(['success' => true, 'result' => ['content_categories' => [['id' => 1, 'name' => 'Social Networks']], 'risk_types' => [['id' => 2, 'name' => 'Phishing']]]])
            ->push([], 403)]);

        $result = app(DomainCategorizer::class)->lookup('social.example');
        $this->assertSame(['Social Networks'], $result['categories']);
        $this->assertSame(['Phishing'], $result['risks']);

        $refused = app(DomainCategorizer::class)->lookup('other.example');
        $this->assertFalse($refused['ok']);
        $this->assertStringContainsString('missing the Intel Read permission', $refused['message']);
    }

    public function test_admin_turns_it_on_with_a_write_only_token(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->put(route('admin.settings.update', 'categories'), ['enabled' => '1', 'source' => 'radar'])->assertSessionHasErrors('token');
        $this->actingAs($admin)->put(route('admin.settings.update', 'categories'), ['enabled' => '1', 'source' => 'radar', 'token' => 'secret-token'])->assertSessionHas('success');
        $this->assertTrue(app(DomainCategorizer::class)->enabled());
        $this->actingAs($admin)->get(route('admin.settings.show', 'categories'))->assertOk()->assertSee('saved; leave blank to keep it')->assertDontSee('secret-token');
    }

    public function test_auto_asks_intel_first_and_falls_back_to_radar_with_an_explanation(): void
    {
        IntegrationConfig::query()->create(['provider' => DomainCategorizer::PROVIDER, 'name' => 'Website categories', 'mode' => 'read_only', 'enabled' => true,
            'configuration' => ['source' => 'auto', 'account_id' => str_repeat('b', 32)], 'credentials' => ['token' => 't']]);
        Http::fake([
            'api.cloudflare.com/client/v4/accounts/*' => Http::response(['success' => false], 403),
            'api.cloudflare.com/client/v4/radar/ranking/domain/popular.example' => Http::response(['result' => ['details_0' => ['categories' => [['name' => 'Social Networks']]]]]),
            'api.cloudflare.com/client/v4/radar/ranking/domain/small.example' => Http::response(['result' => ['details_0' => ['categories' => []]]]),
        ]);

        $this->assertSame(['Social Networks'], app(DomainCategorizer::class)->lookup('popular.example')['categories']);
        $small = app(DomainCategorizer::class)->lookup('small.example');
        $this->assertSame([], $small['categories']);
        $this->assertStringContainsString('missing the Intel Read permission', $small['message']);
        $this->assertStringContainsString('Radar only categorises popular sites', $small['message']);
    }
}
