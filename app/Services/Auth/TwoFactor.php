<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\SettingsRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Two-factor sign-in with time-based one-time codes (TOTP, RFC 6238: 6
 * digits, 30 seconds, SHA-1), which every authenticator app supports, plus
 * one-time recovery codes. A code is accepted once, within one step either
 * side of now, and five wrong codes lock that account's second step for 15
 * minutes.
 *
 * Who needs it (Settings → Authentication): by default IT officers and
 * admins who sign in with a password; optionally also after single sign-on,
 * where the identity provider usually asks for its own second factor.
 */
class TwoFactor
{
    public const SETTINGS_KEY = 'auth.two_factor';

    public const REQUIRED_FOR = ['it' => 'IT officers and admins', 'admin' => 'Admins only', 'off' => 'Nobody (optional for everyone)'];

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly SettingsRepository $settings) {}

    /** @return array{required_for: string, after_sso: bool} */
    public function policy(): array
    {
        $stored = (array) $this->settings->get(self::SETTINGS_KEY, []);
        $for = (string) ($stored['required_for'] ?? config('auth.two_factor.required_for', 'it'));

        return ['required_for' => array_key_exists($for, self::REQUIRED_FOR) ? $for : 'it', 'after_sso' => (bool) ($stored['after_sso'] ?? false)];
    }

    /** Whether this account must use a second factor when it signs in this way ('password' or 'oidc'). */
    public function requiredFor(User $user, string $method): bool
    {
        $policy = $this->policy();
        $role = match ($policy['required_for']) {
            'it' => $user->isIt(),
            'admin' => $user->isAdmin(),
            default => false,
        };

        return $role && ($method === 'password' || $policy['after_sso']);
    }

    /** Whether the second step is asked for at sign-in: always for a password sign-in once set up; after SSO only when the policy says so. */
    public function challengeFor(User $user, string $method): bool
    {
        return $user->hasTwoFactor() && ($method === 'password' || $this->policy()['after_sso']);
    }

    public function newSecret(): string
    {
        $bytes = random_bytes(20);
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        return implode('', array_map(fn (string $chunk) => self::ALPHABET[bindec(str_pad($chunk, 5, '0'))], str_split($bits, 5)));
    }

    /** The link authenticator apps open (otpauth://), naming the app and the account. */
    public function uri(User $user, string $secret): string
    {
        $issuer = (string) config('app.name', 'Filterdesk');

        return 'otpauth://totp/'.rawurlencode($issuer.':'.$user->email).'?'.http_build_query(['secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => 6, 'period' => 30]);
    }

    public function code(string $secret, int $step): string
    {
        $key = $this->decode($secret);
        $hash = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    /** Check a code against a secret (one step either side), never accepting the same step twice for an account. */
    public function verifyCode(string $secret, string $code, ?User $user = null): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== 6) {
            return false;
        }
        $now = intdiv(time(), 30);
        $last = $user ? (int) Cache::get("two_factor.last_step.{$user->id}", 0) : 0;
        foreach ([0, -1, 1] as $drift) {
            $step = $now + $drift;
            if ($step > $last && hash_equals($this->code($secret, $step), $code)) {
                if ($user) {
                    Cache::put("two_factor.last_step.{$user->id}", $step, 120);
                }

                return true;
            }
        }

        return false;
    }

    /**
     * The second step at sign-in: a code from the app, or a recovery code
     * (used up). Locked for 15 minutes after five wrong tries.
     *
     * @return array{ok: bool, recovery?: bool, locked?: int}
     */
    public function attempt(User $user, string $input): array
    {
        $key = "two-factor:{$user->id}";
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return ['ok' => false, 'locked' => RateLimiter::availableIn($key)];
        }
        if ($user->hasTwoFactor() && $this->verifyCode((string) $user->two_factor_secret, $input, $user)) {
            RateLimiter::clear($key);

            return ['ok' => true];
        }
        if ($this->useRecoveryCode($user, $input)) {
            RateLimiter::clear($key);

            return ['ok' => true, 'recovery' => true];
        }
        RateLimiter::hit($key, 900);

        return ['ok' => false];
    }

    /** @return list<string> new recovery codes, shown once; only their hashes are kept */
    public function newRecoveryCodes(User $user): array
    {
        $codes = array_map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)), range(1, 8));
        $user->forceFill(['two_factor_recovery_codes' => array_map(fn (string $code) => hash('sha256', $code), $codes)])->save();

        return $codes;
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return count((array) $user->two_factor_recovery_codes);
    }

    public function disable(User $user): void
    {
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
    }

    private function useRecoveryCode(User $user, string $input): bool
    {
        $hash = hash('sha256', Str::lower(trim($input)));
        $codes = (array) $user->two_factor_recovery_codes;
        foreach ($codes as $index => $stored) {
            if (hash_equals((string) $stored, $hash)) {
                unset($codes[$index]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    private function decode(string $secret): string
    {
        $bits = '';
        foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $secret) ?? '')) as $char) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }

        return implode('', array_map(fn (string $byte) => chr(bindec($byte)), array_filter(str_split($bits, 8), fn (string $byte) => strlen($byte) === 8)));
    }
}
