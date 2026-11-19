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
}
