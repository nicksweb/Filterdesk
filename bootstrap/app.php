<?php

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\InstallationOnly;
use App\Http\Middleware\RequireRole;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'installed' => EnsureInstalled::class,
            'installation.only' => InstallationOnly::class,
            'role' => RequireRole::class,
        ]);
        $middleware->prependToGroup('web', EnsureInstalled::class);
        $middleware->appendToGroup('web', SecurityHeaders::class);
        $middleware->redirectGuestsTo(fn () => route('login'));

        $trusted = array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1')))));
        $middleware->trustProxies(at: $trusted);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
