<?php

use App\Http\Controllers\Admin\IntegrationController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\ItRequestActionController;
use App\Http\Controllers\WebsiteCheckController;
use App\Http\Controllers\WebsiteRequestController;
use Illuminate\Support\Facades\Route;

Route::middleware('installation.only')->group(function (): void {
    Route::get('/install/{step?}', [InstallController::class, 'show'])->whereNumber('step')->name('install.show');
    Route::post('/install/{step}', [InstallController::class, 'store'])->whereNumber('step')->name('install.store');
});

Route::middleware('installed')->group(function (): void {
    Route::get('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/login', [AuthController::class, 'local'])->middleware('throttle:login')->name('auth.local');
    Route::get('/auth/oidc', [AuthController::class, 'oidcRedirect'])->middleware('throttle:20,1')->name('auth.oidc');
    Route::get('/auth/oidc/callback', [AuthController::class, 'oidcCallback'])->middleware('throttle:20,1')->name('auth.oidc.callback');

    Route::middleware('auth')->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::get('/check', [WebsiteCheckController::class, 'create'])->name('checks.create');
        Route::post('/check', [WebsiteCheckController::class, 'store'])->middleware('throttle:30,1')->name('checks.store');
        Route::resource('requests', WebsiteRequestController::class)
            ->parameters(['requests' => 'websiteRequest'])
            ->only(['index', 'create', 'store', 'show']);
        Route::post('/requests/{websiteRequest}/reply', [WebsiteRequestController::class, 'reply'])->name('requests.reply');

        Route::middleware('role:it,admin')->group(function (): void {
            Route::post('/it/requests/{websiteRequest}/action', ItRequestActionController::class)->name('it.requests.action');
        });

        Route::prefix('admin')->middleware('role:admin')->name('admin.')->group(function (): void {
            Route::get('/users', [UserController::class, 'index'])->name('users.index');
            Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
            Route::get('/integrations', [IntegrationController::class, 'index'])->name('integrations.index');
            Route::post('/integrations/test', [IntegrationController::class, 'test'])->name('integrations.test');
        });
    });
});
