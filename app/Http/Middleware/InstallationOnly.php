<?php

namespace App\Http\Middleware;

use App\Services\SettingsRepository;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InstallationOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app(SettingsRepository::class)->installed()) {
            abort(404);
        }

        return $next($request);
    }
}
