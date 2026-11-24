<?php

namespace App\Http\Middleware;

use App\Services\Auth\TwoFactor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An account that must use two-factor sign-in (Settings → Authentication)
 * and hasn't set it up can only reach the set-up page until it has.
 */
class RequireTwoFactor
{
    private const ALLOWED = ['profile.two-factor', 'profile.two-factor.start', 'profile.two-factor.confirm', 'logout', 'impersonation.stop'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || $user->hasTwoFactor() || in_array($request->route()?->getName(), self::ALLOWED, true)) {
            return $next($request);
        }
        $method = (string) $request->session()->get('auth.method', $user->auth_source === 'oidc' ? 'oidc' : 'password');
        if (! app(TwoFactor::class)->requiredFor($user, $method)) {
            return $next($request);
        }

        return $request->expectsJson()
            ? response()->json(['message' => 'Set up two-factor sign-in first.'], 403)
            : redirect()->route('profile.two-factor')->with('error', 'Your account needs two-factor sign-in. Set it up to carry on.');
    }
}
