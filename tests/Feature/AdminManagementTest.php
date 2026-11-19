<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::query()->create(['key' => 'installation.completed', 'value' => true]);
    }

    public function test_admin_can_create_a_local_dummy_user(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Dummy Teacher',
            'email' => 'dummy.teacher@example.edu.au',
            'role' => 'teacher',
            'password' => 'temporary-password-2026',
            'password_confirmation' => 'temporary-password-2026',
            'enabled' => '1',
        ])->assertRedirect();

        $user = User::query()->where('email', 'dummy.teacher@example.edu.au')->firstOrFail();
        $this->assertSame('teacher', $user->role);
        $this->assertTrue($user->enabled);
        $this->assertTrue(Hash::check('temporary-password-2026', $user->password));
    }
}
