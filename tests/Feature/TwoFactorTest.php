<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\TwoFactor;
use App\Services\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

/**
 * Two-factor sign-in: codes follow RFC 6238, an IT or admin password
 * sign-in must set it up, then needs a code (or a recovery code, used once),
 * wrong codes lock it, and an admin or the host can reset it.
 */
class TwoFactorTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    private TwoFactor $twoFactor;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::flush();
        $this->installWithPolicies();
        $this->twoFactor = app(TwoFactor::class);
        app(SettingsRepository::class)->set(TwoFactor::SETTINGS_KEY, ['required_for' => 'it', 'after_sso' => false]);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['email' => 'admin@example.edu', 'password' => 'a-long-password-123', 'password_login_enabled' => true, 'auth_source' => 'local']);
    }

    private function nowCode(string $secret, int $drift = 0): string
    {
        return $this->twoFactor->code($secret, intdiv(time(), 30) + $drift);
    }

    public function test_codes_match_the_rfc_6238_test_vector(): void
    {
        // RFC 6238 appendix B: secret "12345678901234567890" (base32 below), T = 59 s → 94287082 (8 digits; 287082 in 6).
        $this->assertSame('287082', $this->twoFactor->code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 1));
        $this->assertSame(32, strlen($this->twoFactor->newSecret()));
    }

    public function test_an_admin_signing_in_with_a_password_must_set_it_up_then_needs_a_code(): void
    {
        $admin = $this->admin();
        $this->post(route('auth.local'), ['email' => 'admin@example.edu', 'password' => 'a-long-password-123'])->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertRedirect(route('profile.two-factor'));

        $this->post(route('profile.two-factor.start'))->assertRedirect(route('profile.two-factor'));
        $secret = session('two_factor.secret');
        $this->get(route('profile.two-factor'))->assertOk()->assertSee(trim(chunk_split($secret, 4, ' ')))->assertSee('otpauth://totp/', false);
        $this->post(route('profile.two-factor.confirm'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->post(route('profile.two-factor.confirm'), ['code' => $this->nowCode($secret)])->assertSessionHas('recovery_codes');
        $codes = session('recovery_codes');
        $this->assertTrue($admin->refresh()->hasTwoFactor());
        $this->assertNotSame($secret, $admin->getRawOriginal('two_factor_secret'), 'Stored encrypted');
        $this->get(route('dashboard'))->assertOk();

        // Next sign-in: password, then the code.
        $this->post(route('logout'));
        Cache::flush();
        $this->post(route('auth.local'), ['email' => 'admin@example.edu', 'password' => 'a-long-password-123'])->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
        $this->get(route('two-factor.challenge'))->assertOk()->assertSee('Enter your code');
        $this->post(route('two-factor.verify'), ['code' => '123456'])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->post(route('two-factor.verify'), ['code' => $this->nowCode($secret)])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($admin);

        // A recovery code works once.
        $this->post(route('logout'));
        $this->post(route('auth.local'), ['email' => 'admin@example.edu', 'password' => 'a-long-password-123']);
        $this->post(route('two-factor.verify'), ['code' => $codes[0]])->assertRedirect(route('dashboard'));
        $this->assertSame(7, $this->twoFactor->remainingRecoveryCodes($admin->refresh()));
        $this->post(route('logout'));
        $this->post(route('auth.local'), ['email' => 'admin@example.edu', 'password' => 'a-long-password-123']);
        $this->post(route('two-factor.verify'), ['code' => $codes[0]])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_wrong_codes_lock_the_second_step_and_an_admin_can_reset_it(): void
    {
        $admin = $this->admin();
        $secret = $this->twoFactor->newSecret();
        $admin->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();

        $this->post(route('auth.local'), ['email' => 'admin@example.edu', 'password' => 'a-long-password-123']);
        foreach (range(1, 5) as $try) {
            $this->post(route('two-factor.verify'), ['code' => '000000']);
        }
        $this->post(route('two-factor.verify'), ['code' => $this->nowCode($secret)])->assertSessionHasErrors(['code' => 'Too many wrong codes. Try again in 15 minute(s), or ask an admin to reset your two-factor sign-in.']);
        $this->assertGuest();

        $other = User::factory()->admin()->create(['two_factor_secret' => $this->twoFactor->newSecret(), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($other)->post(route('admin.users.two-factor-reset', $admin))->assertSessionHas('success');
        $this->assertFalse($admin->refresh()->hasTwoFactor());

        $admin->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();
        $this->artisan('filterdesk:reset-two-factor', ['email' => 'admin@example.edu'])->assertSuccessful();
        $this->assertFalse($admin->refresh()->hasTwoFactor());
    }

    public function test_staff_and_sso_admins_are_not_forced_by_default(): void
    {
        $teacher = User::factory()->teacher()->create(['auth_source' => 'local']);
        $this->actingAs($teacher)->get(route('dashboard'))->assertOk();
        $ssoAdmin = User::factory()->admin()->create(['auth_source' => 'oidc']);
        $this->actingAs($ssoAdmin)->get(route('dashboard'))->assertOk();

        app(SettingsRepository::class)->set(TwoFactor::SETTINGS_KEY, ['required_for' => 'it', 'after_sso' => true]);
        $this->actingAs($ssoAdmin)->get(route('dashboard'))->assertRedirect(route('profile.two-factor'));
    }

    public function test_a_code_is_not_accepted_twice(): void
    {
        $user = $this->admin();
        $secret = $this->twoFactor->newSecret();
        $code = $this->nowCode($secret);
        $this->assertTrue($this->twoFactor->verifyCode($secret, $code, $user));
        $this->assertFalse($this->twoFactor->verifyCode($secret, $code, $user));
    }
}
