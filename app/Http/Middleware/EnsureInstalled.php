<?php

namespace App\Http\Middleware;

use App\Services\SettingsRepository;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app(SettingsRepository::class)->installed() && ! $request->routeIs('install.*')) {
            return redirect()->route('install.show', 1);
        }

        return $next($request);
    }
}
