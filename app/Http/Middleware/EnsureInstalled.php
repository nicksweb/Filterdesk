<?php

namespace App\Http\Middleware;

use App\Http\Controllers\OidcTestController;
use App\Services\SettingsRepository;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        // The installer's Test sign-in comes back through the normal callback. This also runs before
        // the session starts (prepended to the web group); the route's own check, after it, decides.
        $testing = $request->routeIs('auth.oidc.callback') && (! $request->hasSession() || $request->session()->has(OidcTestController::SESSION_KEY));
        if (! app(SettingsRepository::class)->installed() && ! $testing && ! $request->routeIs('install.*', 'health')) {
            return redirect()->route('install.show', 1);
        }

        return $next($request);
    }
}
