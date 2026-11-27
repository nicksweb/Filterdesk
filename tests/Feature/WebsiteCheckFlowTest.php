<?php

namespace Tests\Feature;

use App\Models\NetworkProfile;
use App\Models\Setting;
use App\Models\User;
use App\Models\WebsiteCheck;
use App\Services\Website\ProbeSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebsiteCheckFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::query()->create(['key' => 'installation.completed', 'value' => true]);
        Setting::query()->create(['key' => 'approval', 'value' => ['categories' => ['Teaching and learning']]]);
        Setting::query()->create(['key' => ProbeSettings::PREVIEWS_KEY, 'value' => 'staff']);
        NetworkProfile::query()->create(['key' => 'student', 'name' => 'Student Access', 'probe_url' => 'http://probe-student:3000']);
        NetworkProfile::query()->create(['key' => 'staff', 'name' => 'Staff Access', 'probe_url' => 'http://probe-staff:3000']);
    }

    public function test_multiple_websites_return_screenshots_and_are_stored_as_individual_checks(): void
    {
        Http::fake(fn ($request) => Http::response(['results' => array_map(fn () => [
            'status' => 'blocked',
            'http_status' => 200,
            'page_title' => 'Access blocked',
            'final_url' => 'https://example.com/',
            'addresses' => ['203.0.113.10'],
            'duration_ms' => 230,
            'screenshot' => base64_encode('jpeg'),
            'hosts' => [['host' => 'cdn.example.net', 'requests' => 0, 'failed' => true]],
        ], $request['urls'])]));

        $teacher = User::factory()->create(['role' => 'teacher']);
        $response = $this->actingAs($teacher)->post(route('checks.store'), [
            'websites' => "example.com\nhttps://learning.example.org/resource",
        ]);

        $response->assertOk()
            ->assertSee('2 websites checked')
            ->assertSee('data:image/jpeg;base64,'.base64_encode('jpeg'), false)
            ->assertSee('Ask IT');
        $this->assertSame(2, WebsiteCheck::query()->count());
        $this->assertArrayNotHasKey('screenshot', WebsiteCheck::query()->first()->results['student']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/check-batch') && count($request['urls']) === 2);
    }

    public function test_by_default_only_it_sees_page_previews(): void
    {
        Setting::query()->where('key', ProbeSettings::PREVIEWS_KEY)->delete();
        NetworkProfile::query()->update(['audience' => 'student']);
        Http::fake(fn ($request) => Http::response(['results' => array_map(fn () => [
            'status' => 'allowed', 'http_status' => 200, 'page_title' => 'Something unsuitable', 'final_url' => 'https://example.com/',
            'addresses' => ['198.18.0.10'], 'duration_ms' => 230, 'screenshot' => $request['screenshot'] ? base64_encode('jpeg') : null, 'hosts' => [],
        ], $request['urls'])]));

        $student = User::factory()->create(['role' => 'student']);
        $this->actingAs($student)->post(route('checks.store'), ['websites' => 'example.com'])->assertOk()
            ->assertSee('Allowed')->assertDontSee('Something unsuitable')->assertDontSee('data:image/jpeg', false)->assertDontSee('Screenshot unavailable');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/check-batch') && $request['screenshot'] === false);

        $teacher = User::factory()->create(['role' => 'teacher']);
        $this->actingAs($teacher)->post(route('checks.store'), ['websites' => 'example.com'])->assertOk()->assertDontSee('Something unsuitable');

        $it = User::factory()->admin()->create();
        $this->actingAs($it)->post(route('checks.store'), ['websites' => 'example.com'])->assertOk()
            ->assertSee('Something unsuitable')->assertSee('data:image/jpeg', false);
    }

    public function test_dns_only_checks_never_load_the_page(): void
    {
        Setting::query()->create(['key' => ProbeSettings::METHOD_KEY, 'value' => 'dns']);
        Http::fake(fn ($request) => Http::response(['results' => array_map(fn () => [
            'status' => 'allowed', 'dns_only' => true, 'addresses' => ['198.18.0.10'], 'duration_ms' => 3, 'screenshot' => null, 'hosts' => [],
        ], $request['urls'])]));

        $this->actingAs(User::factory()->admin()->create())->post(route('checks.store'), ['websites' => 'example.com'])->assertOk()
            ->assertSee('Resolves normally')->assertSee('the page was not loaded');
        Http::assertSent(fn ($request) => $request['dns_only'] === true && $request['screenshot'] === false);
    }

    public function test_a_dns_blocked_result_says_so_instead_of_an_empty_screenshot(): void
    {
        Http::fake(fn ($request) => Http::response(['results' => array_map(fn () => [
            'status' => 'blocked', 'block_method' => 'dns', 'addresses' => ['203.0.113.250'], 'duration_ms' => 3, 'screenshot' => null, 'hosts' => [],
        ], $request['urls'])]));

        $this->actingAs(User::factory()->admin()->create())->post(route('checks.store'), ['websites' => 'ometv.example'])->assertOk()
            ->assertSee('Blocked by DNS')->assertSee('203.0.113.250')->assertDontSee('Screenshot unavailable');
    }

    public function test_selected_checked_domains_prefill_one_access_request(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->actingAs($teacher)->get(route('requests.create', [
            'domains' => ['Example.COM', 'learning.example.org'],
        ]))->assertOk()
            ->assertSee("example.com\nlearning.example.org");
    }
}
