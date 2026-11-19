<?php

namespace Tests\Feature;

use App\Models\NetworkProfile;
use App\Models\Setting;
use App\Models\User;
use App\Models\WebsiteCheck;
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
        NetworkProfile::query()->create(['key' => 'student', 'name' => 'Student Access', 'probe_url' => 'http://probe-student:3000']);
        NetworkProfile::query()->create(['key' => 'staff', 'name' => 'Staff Access', 'probe_url' => 'http://probe-staff:3000']);
    }

    public function test_multiple_websites_return_screenshots_and_are_stored_as_individual_checks(): void
    {
        Http::fake(fn () => Http::response([
            'status' => 'blocked',
            'http_status' => 200,
            'page_title' => 'Access blocked',
            'final_url' => 'https://example.com/',
            'addresses' => ['203.0.113.10'],
            'duration_ms' => 230,
            'screenshot' => base64_encode('jpeg'),
        ]));

        $teacher = User::factory()->create(['role' => 'teacher']);
        $response = $this->actingAs($teacher)->post(route('checks.store'), [
            'websites' => "example.com\nhttps://learning.example.org/resource",
        ]);

        $response->assertOk()
            ->assertSee('2 websites checked')
            ->assertSee('data:image/jpeg;base64,'.base64_encode('jpeg'), false)
            ->assertSee('Request access for selected');
        $this->assertSame(2, WebsiteCheck::query()->count());
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
