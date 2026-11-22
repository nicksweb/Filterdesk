<?php

namespace App\Services\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Brute-force protection for password sign-in, on top of the per-minute
 * route throttle:
 *
 * - account lockout: 5 failed attempts for one email address within 15
 *   minutes locks password sign-in for that address for 15 minutes, from
 *   any IP address;
 * - IP limit: 20 failed attempts from one address within 15 minutes blocks
 *   that address, which stops password spraying across many accounts.
 */
class LoginThrottle
{
    public const ACCOUNT_ATTEMPTS = 5;

    public const IP_ATTEMPTS = 20;

    public const DECAY_SECONDS = 900;

    /** Seconds until sign-in may be tried again, or 0 when allowed. */
    public function blockedFor(Request $request): int
    {
        foreach ([[$this->accountKey($request), self::ACCOUNT_ATTEMPTS], [$this->ipKey($request), self::IP_ATTEMPTS]] as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return RateLimiter::availableIn($key);
            }
        }

        return 0;
    }

    /**
     * Record a failed attempt.
     *
     * @return bool true when this failure locked the account
     */
    public function failed(Request $request): bool
    {
        RateLimiter::hit($this->ipKey($request), self::DECAY_SECONDS);
        $attempts = RateLimiter::hit($this->accountKey($request), self::DECAY_SECONDS);

        return $attempts === self::ACCOUNT_ATTEMPTS;
    }

    public function succeeded(Request $request): void
    {
        RateLimiter::clear($this->accountKey($request));
    }

    private function accountKey(Request $request): string
    {
        return 'login-account:'.sha1(strtolower(trim((string) $request->input('email'))));
    }

    private function ipKey(Request $request): string
    {
        return 'login-ip:'.$request->ip();
    }
}
