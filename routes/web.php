<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\DiagnosticsController;
use App\Http\Controllers\Admin\FilterPolicyController;
use App\Http\Controllers\Admin\PolicyController;
use App\Http\Controllers\Admin\PolicyListController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\ItRequestActionController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WebsiteCheckController;
use App\Http\Controllers\WebsiteRequestController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');

Route::middleware('installation.only')->group(function (): void {
    Route::get('/install/{step?}', [InstallController::class, 'show'])->whereNumber('step')->name('install.show');
    Route::post('/install/{step}', [InstallController::class, 'store'])->whereNumber('step')->middleware('throttle:30,1')->name('install.store');
});

Route::middleware('installed')->group(function (): void {
    Route::get('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/login', [AuthController::class, 'local'])->middleware('throttle:login')->name('auth.local');
    Route::get('/auth/oidc', [AuthController::class, 'oidcRedirect'])->middleware('throttle:20,1')->name('auth.oidc');
    Route::get('/auth/oidc/callback', [AuthController::class, 'oidcCallback'])->middleware('throttle:20,1')->name('auth.oidc.callback');

    Route::middleware(['auth', 'enabled'])->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::get('/check', [WebsiteCheckController::class, 'create'])->name('checks.create');
        Route::post('/check', [WebsiteCheckController::class, 'store'])->middleware('throttle:30,1')->name('checks.store');
        Route::resource('requests', WebsiteRequestController::class)
            ->parameters(['requests' => 'websiteRequest'])
            ->only(['index', 'create', 'store', 'show']);
        Route::post('/requests/{websiteRequest}/reply', [WebsiteRequestController::class, 'reply'])->name('requests.reply');

        Route::middleware('role:it,admin')->group(function (): void {
            Route::post('/it/requests/{websiteRequest}/action', ItRequestActionController::class)->name('it.requests.action');

            Route::prefix('admin/policy')->name('admin.policy.')->group(function (): void {
                Route::get('/', [PolicyController::class, 'index'])->name('index');
                Route::post('/entries', [PolicyController::class, 'storeEntries'])->name('entries.store');
                Route::delete('/entries/{entry}', [PolicyController::class, 'destroyEntry'])->name('entries.destroy');

                Route::get('/lists/{list}', [PolicyListController::class, 'show'])->name('lists.show');
                Route::post('/lists/{list}/import', [PolicyListController::class, 'import'])->name('lists.import');
                Route::get('/lists/{list}/export', [PolicyListController::class, 'export'])->name('lists.export');

                Route::get('/policies/{policy}', [FilterPolicyController::class, 'show'])->name('policies.show');
                Route::post('/policies/{policy}/snapshot', [FilterPolicyController::class, 'snapshot'])->name('policies.snapshot');
                Route::post('/policies/{policy}/synced', [FilterPolicyController::class, 'markSynced'])->name('policies.synced');
                Route::get('/policies/{policy}/export/{kind}', [FilterPolicyController::class, 'export'])->name('policies.export');

                Route::middleware('role:admin')->group(function (): void {
                    Route::post('/lists', [PolicyListController::class, 'store'])->name('lists.store');
                    Route::put('/lists/{list}', [PolicyListController::class, 'update'])->name('lists.update');
                    Route::delete('/lists/{list}', [PolicyListController::class, 'destroy'])->name('lists.destroy');
                    Route::post('/policies', [FilterPolicyController::class, 'store'])->name('policies.store');
                    Route::put('/policies/{policy}', [FilterPolicyController::class, 'update'])->name('policies.update');
                    Route::delete('/policies/{policy}', [FilterPolicyController::class, 'destroy'])->name('policies.destroy');
                });
            });
        });

        Route::prefix('admin')->middleware('role:admin')->name('admin.')->group(function (): void {
            Route::get('/users', [UserController::class, 'index'])->name('users.index');
            Route::post('/users', [UserController::class, 'store'])->name('users.store');
            Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
            Route::get('/settings/{section?}', [SettingsController::class, 'show'])->defaults('section', 'general')->name('settings.show');
            Route::put('/settings/{section}', [SettingsController::class, 'update'])->name('settings.update');
            Route::post('/settings/email/test', [SettingsController::class, 'testEmail'])->middleware('throttle:6,1')->name('settings.test-email');
            Route::post('/settings/network/test', [SettingsController::class, 'testNetwork'])->middleware('throttle:10,1')->name('settings.test-network');
            Route::post('/settings/network/discover', [SettingsController::class, 'discover'])->middleware('throttle:10,1')->name('settings.discover');
            Route::get('/audit', AuditLogController::class)->name('audit');
            Route::get('/diagnostics', DiagnosticsController::class)->name('diagnostics');
            Route::redirect('/integrations', '/admin/settings/network')->name('integrations.index');
        });
    });
});
