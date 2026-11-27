<?php

namespace App\Http\Middleware;

use App\Services\SettingsRepository;
use App\Services\Website\ProbeSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $schoolbox = app(SettingsRepository::class)->get('school.schoolbox_origin');
        $ancestors = $schoolbox ? "'self' {$schoolbox}" : "'self'";
        // The embeddable website check may also be framed by the sites listed under Website checks.
        if ($request->routeIs('checks.embed', 'checks.embed.store')) {
            $ancestors = trim($ancestors.' '.implode(' ', app(ProbeSettings::class)->embedOrigins()));
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('Content-Security-Policy', "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors {$ancestors}; base-uri 'self'; form-action 'self'");

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
