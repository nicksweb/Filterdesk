<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_installer_is_locked_after_completion(): void
    {
        Setting::query()->create(['key' => 'installation.completed', 'value' => true]);
        $this->get('/install/1')->assertNotFound();
    }

    public function test_uninstalled_application_redirects_to_installer(): void
    {
        $this->get('/')->assertRedirect('/install/1');
    }

    public function test_first_step_explains_an_untrusted_reverse_proxy(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])->withHeader('X-Forwarded-Proto', 'https')
            ->get('http://localhost/install/1')->assertOk()->assertSee('Set TRUSTED_PROXIES=10.9.9.9', false);
    }
}
