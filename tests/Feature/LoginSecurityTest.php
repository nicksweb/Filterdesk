<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\IntegrationConfig;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\AccountLockedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class LoginSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Setting::query()->create(['key' => 'installation.completed', 'value' => true]);
        $this->admin = User::factory()->admin()->create(['email' => 'admin@example.test', 'password' => 'correct horse battery staple']);
    }

    private function attemptLogin(string $password, string $ip = '203.0.113.5')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->post(route('auth.local'), ['email' => 'admin@example.test', 'password' => $password]);
    }

    public function test_an_account_locks_after_five_failures_from_any_address_and_alerts_the_owner(): void
    {
        foreach (range(1, 5) as $index) {
            $this->attemptLogin('wrong', "203.0.113.{$index}")->assertSessionHasErrors('email');
        }

        Notification::assertSentTo($this->admin, AccountLockedNotification::class);
        $this->attemptLogin('correct horse battery staple', '198.51.100.7')->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame(5, AuditEvent::query()->whereIn('action', ['auth.login_failed', 'auth.account_locked'])->count());
        $this->assertTrue(AuditEvent::query()->where('action', 'auth.login_blocked')->exists());

        $this->travel(16)->minutes();
        $this->attemptLogin('correct horse battery staple')->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->admin);
    }

    public function test_password_sign_in_can_be_turned_off_per_account(): void
    {
        $this->admin->update(['password_login_enabled' => false]);

        $this->attemptLogin('correct horse battery staple')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_admins_cannot_turn_off_their_own_password_sign_in_without_sso(): void
    {
        $this->actingAs($this->admin)->put(route('admin.users.update', $this->admin), ['role' => 'admin', 'enabled' => '1', 'password_login_enabled' => '0'])
            ->assertSessionHas('error');
        $this->assertTrue($this->admin->refresh()->password_login_enabled);

        IntegrationConfig::query()->create(['provider' => 'oidc', 'name' => 'SSO', 'mode' => 'active', 'enabled' => true, 'configuration' => []]);
        $this->actingAs($this->admin)->put(route('admin.users.update', $this->admin), ['role' => 'admin', 'enabled' => '1', 'password_login_enabled' => '0'])
            ->assertSessionHas('success');
        $this->assertFalse($this->admin->refresh()->password_login_enabled);
    }
}
