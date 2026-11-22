<?php

namespace Tests\Feature;

use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    public function test_the_app_can_be_renamed_and_given_its_own_icon(): void
    {
        $this->installWithPolicies();
        $admin = User::factory()->admin()->create();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

        $this->get(route('brand.icon'))->assertOk()->assertHeader('Content-Type', 'image/x-icon');

        $this->actingAs($admin)->put(route('admin.settings.update', 'general'), [
            'school_name' => 'Example College', 'timezone' => 'Australia/Brisbane', 'app_url' => 'http://localhost',
            'app_name' => 'uConnect', 'brand_mark' => 'uC', 'icon' => UploadedFile::fake()->createWithContent('icon.png', $png),
        ])->assertSessionHas('success');

        (new AppServiceProvider($this->app))->boot();
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertSee('uConnect')->assertSee('class="brand-icon"', false)->assertSee('rel="icon"', false);
        $icon = $this->get(route('brand.icon'))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame($png, $icon->getContent());
        $this->get(route('brand.icon'), ['If-None-Match' => $icon->headers->get('ETag')])->assertStatus(304);

        $this->actingAs($admin)->put(route('admin.settings.update', 'general'), [
            'school_name' => 'Example College', 'timezone' => 'Australia/Brisbane', 'app_url' => 'http://localhost',
            'icon' => UploadedFile::fake()->create('bad.exe', 10, 'application/octet-stream'),
        ])->assertSessionHasErrors('icon');
    }
}
