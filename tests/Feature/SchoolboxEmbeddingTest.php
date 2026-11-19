<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchoolboxEmbeddingTest extends TestCase
{
    use RefreshDatabase;

    public function test_configured_schoolbox_origin_is_allowed_and_receives_cross_site_session_cookie(): void
    {
        Setting::query()->create(['key' => 'installation.completed', 'value' => true]);
        Setting::query()->create(['key' => 'school', 'value' => [
            'name' => 'Example School',
            'schoolbox_origin' => 'https://schoolbox.example.edu.au',
        ]]);

        // Production startup reads the complete school setting before middleware
        // requests a nested value; these cache entries must remain independent.
        app(SettingsRepository::class)->get('school');

        $response = $this->get('/login');

        $response->assertOk()
            ->assertHeader('Content-Security-Policy', "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'self' https://schoolbox.example.edu.au; base-uri 'self'; form-action 'self'");

        $cookies = implode('; ', $response->headers->all('set-cookie'));
        $this->assertStringContainsStringIgnoringCase('samesite=none', $cookies);
        $this->assertStringContainsStringIgnoringCase('secure', $cookies);
    }
}
