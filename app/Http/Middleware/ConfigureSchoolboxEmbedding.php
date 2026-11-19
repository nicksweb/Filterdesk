<?php

namespace App\Http\Middleware;

use App\Services\SettingsRepository;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ConfigureSchoolboxEmbedding
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app(SettingsRepository::class)->get('school.schoolbox_origin')) {
            config([
                'session.same_site' => 'none',
                'session.secure' => true,
            ]);
        }

        return $next($request);
    }
}
