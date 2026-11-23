<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token authentication for /api: the token acts as the enabled IT
 * officer who created it, limited to the ability the route needs.
 */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next, string $ability = 'read'): Response
    {
        $plain = (string) $request->bearerToken();
        $token = $plain !== '' ? ApiToken::findPlain($plain) : null;
        $user = $token?->user;

        if (! $token || ! $user || ! $user->enabled || ! $user->isIt()) {
            return response()->json(['message' => 'A valid API token is required.'], 401);
        }
        if (! $token->can($ability)) {
            return response()->json(['message' => "This token cannot {$ability}."], 403);
        }

        if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinutes(5))) {
            $token->forceFill(['last_used_at' => now()])->save();
        }
        Auth::setUser($user);
        $request->attributes->set('api_token', $token);

        return $next($request);
    }
}
