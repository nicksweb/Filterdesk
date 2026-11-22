<?php

use App\Http\Controllers\AccessExplorerController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BlocklistController;
use App\Http\Controllers\Admin\ChangesController;
use App\Http\Controllers\Admin\ControllerSetupController;
use App\Http\Controllers\Admin\DiagnosticsController;
use App\Http\Controllers\Admin\DomainScanController;
use App\Http\Controllers\Admin\FilterPolicyController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\InsightsController;
use App\Http\Controllers\Admin\NetworkMapController;
use App\Http\Controllers\Admin\NetworkTypePolicyController;
use App\Http\Controllers\Admin\PolicyController;
use App\Http\Controllers\Admin\PolicyLayerController;
use App\Http\Controllers\Admin\PolicyListController;
use App\Http\Controllers\Admin\ProbeSetupController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BrandIconController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExceptionReviewController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\ItRequestActionController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WebsiteCheckController;
use App\Http\Controllers\WebsiteRequestController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');

Route::get('/brand/icon', BrandIconController::class)->name('brand.icon');

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
        Route::post('/impersonation/stop', [ImpersonationController::class, 'stop'])->name('impersonation.stop');
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::get('/check', [WebsiteCheckController::class, 'create'])->name('checks.create');
        Route::post('/check', [WebsiteCheckController::class, 'store'])->middleware('throttle:30,1')->name('checks.store');
        Route::resource('requests', WebsiteRequestController::class)
            ->parameters(['requests' => 'websiteRequest'])
            ->only(['index', 'create', 'store', 'show']);
        Route::post('/requests/{websiteRequest}/reply', [WebsiteRequestController::class, 'reply'])->name('requests.reply');
        Route::get('/why', [AccessExplorerController::class, 'why'])->middleware('throttle:30,1')->name('why');
        Route::get('/reviews', [ExceptionReviewController::class, 'index'])->name('reviews.index');
        Route::post('/reviews/keep', [ExceptionReviewController::class, 'keep'])->name('reviews.keep');

        Route::middleware('role:it,admin')->group(function (): void {
            Route::post('/it/requests/{websiteRequest}/action', ItRequestActionController::class)->name('it.requests.action');
            Route::get('/insights', [InsightsController::class, 'show'])->name('admin.insights');
            Route::get('/insights/requests.csv', [InsightsController::class, 'csv'])->name('admin.insights.csv');

            Route::prefix('admin/policy')->name('admin.policy.')->group(function (): void {
                Route::get('/', [PolicyController::class, 'index'])->name('index');
                Route::post('/entries', [PolicyController::class, 'storeEntries'])->name('entries.store');
                Route::delete('/entries/{entry}', [PolicyController::class, 'destroyEntry'])->name('entries.destroy');

                Route::get('/networks', [NetworkMapController::class, 'index'])->name('networks.index');
                Route::post('/networks/pull', [NetworkMapController::class, 'pullAll'])->middleware('throttle:10,1')->name('networks.pull');
                Route::get('/scan', [DomainScanController::class, 'show'])->name('scan.show');
                Route::post('/scan', [DomainScanController::class, 'store'])->middleware('throttle:10,1')->name('scan.store');

                Route::get('/lists/{list}', [PolicyListController::class, 'show'])->name('lists.show');
                Route::post('/lists/{list}/import', [PolicyListController::class, 'import'])->name('lists.import');
                Route::get('/lists/{list}/export', [PolicyListController::class, 'export'])->name('lists.export');

                Route::get('/policies/{policy}', [FilterPolicyController::class, 'show'])->name('policies.show');
                Route::post('/policies/{policy}/snapshot', [FilterPolicyController::class, 'snapshot'])->name('policies.snapshot');
                Route::post('/policies/{policy}/synced', [FilterPolicyController::class, 'markSynced'])->name('policies.synced');
                Route::post('/policies/{policy}/pull', [FilterPolicyController::class, 'pull'])->middleware('throttle:20,1')->name('policies.pull');
                Route::post('/policies/{policy}/adopt', [FilterPolicyController::class, 'adopt'])->name('policies.adopt');
                Route::post('/policies/{policy}/push', [FilterPolicyController::class, 'push'])->middleware('throttle:20,1')->name('policies.push');
                Route::post('/pushes/{push}/rollback', [FilterPolicyController::class, 'rollback'])->middleware('throttle:10,1')->name('pushes.rollback');
                Route::get('/explorer', [AccessExplorerController::class, 'show'])->middleware('throttle:60,1')->name('explorer');
                Route::get('/types/{type:key}', [NetworkTypePolicyController::class, 'show'])->name('types.show');
                Route::get('/changes', [ChangesController::class, 'index'])->name('changes.index');
                Route::post('/changes/publish', [ChangesController::class, 'publish'])->middleware('throttle:10,1')->name('changes.publish');
                Route::post('/changes/decide', [ChangesController::class, 'decide'])->name('changes.decide');
                Route::get('/versions/{release}', [ChangesController::class, 'show'])->name('versions.show');
                Route::post('/versions/{release}/rollback', [ChangesController::class, 'rollback'])->middleware('throttle:6,1')->name('versions.rollback');
                Route::post('/networks/push', [NetworkMapController::class, 'pushAll'])->middleware('throttle:10,1')->name('networks.push');
                Route::post('/networks/check', [NetworkMapController::class, 'checkSync'])->middleware('throttle:10,1')->name('networks.check');
                Route::post('/policies/{policy}/restore', [FilterPolicyController::class, 'restore'])->name('policies.restore');
                Route::post('/policies/{policy}/accept-controller', [FilterPolicyController::class, 'acceptController'])->middleware('throttle:20,1')->name('policies.accept-controller');
                Route::get('/policies/{policy}/export/{kind}', [FilterPolicyController::class, 'export'])->name('policies.export');

                Route::middleware('role:admin')->group(function (): void {
                    Route::put('/networks', [NetworkMapController::class, 'update'])->name('networks.update');
                    Route::get('/blocklists', [BlocklistController::class, 'index'])->name('blocklists.index');
                    Route::post('/blocklists/feeds', [BlocklistController::class, 'storeFeed'])->middleware('throttle:10,1')->name('blocklists.feeds.store');
                    Route::put('/blocklists/feeds/{source}', [BlocklistController::class, 'updateFeed'])->name('blocklists.feeds.update');
                    Route::post('/blocklists/feeds/{source}/refresh', [BlocklistController::class, 'refreshFeed'])->middleware('throttle:10,1')->name('blocklists.feeds.refresh');
                    Route::delete('/blocklists/feeds/{source}', [BlocklistController::class, 'destroyFeed'])->name('blocklists.feeds.destroy');
                    Route::post('/blocklists/firewall', [BlocklistController::class, 'storeFirewallList'])->name('blocklists.firewall.store');
                    Route::put('/blocklists/firewall/{list}', [BlocklistController::class, 'updateFirewallList'])->name('blocklists.firewall.update');
                    Route::post('/blocklists/firewall/{list}/create-group', [BlocklistController::class, 'createGroup'])->middleware('throttle:6,1')->name('blocklists.firewall.create-group');
                    Route::post('/blocklists/firewall/{list}/rules', [BlocklistController::class, 'rules'])->middleware('throttle:6,1')->name('blocklists.firewall.rules');
                    Route::post('/blocklists/firewall/{list}/push', [BlocklistController::class, 'pushFirewallList'])->middleware('throttle:10,1')->name('blocklists.firewall.push');
                    Route::delete('/blocklists/firewall/{list}', [BlocklistController::class, 'destroyFirewallList'])->name('blocklists.firewall.destroy');
                    Route::get('/setup', [ControllerSetupController::class, 'show'])->name('setup.show');
                    Route::post('/setup', [ControllerSetupController::class, 'store'])->middleware('throttle:10,1')->name('setup.store');
                    Route::post('/setup/probes', [ControllerSetupController::class, 'saveProbes'])->name('setup.probes');
                    Route::post('/setup/profiles', [ControllerSetupController::class, 'profiles'])->name('setup.profiles');
                    Route::post('/setup/import-all', [ControllerSetupController::class, 'importAll'])->middleware('throttle:6,1')->name('setup.import-all');
                    Route::get('/setup/compose-vlan', [ControllerSetupController::class, 'compose'])->name('setup.compose');
                    Route::post('/networks/link', [NetworkMapController::class, 'link'])->name('networks.link');
                    Route::post('/policies/{policy}/categories', [FilterPolicyController::class, 'categories'])->middleware('throttle:10,1')->name('policies.categories');
                    Route::post('/policies/{policy}/layer', [PolicyLayerController::class, 'store'])->middleware('throttle:6,1')->name('policies.layer.store');
                    Route::post('/policies/{policy}/verify-write', [FilterPolicyController::class, 'verifyWrite'])->middleware('throttle:6,1')->name('policies.verify-write');
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
            Route::post('/users/{user}/impersonate', [ImpersonationController::class, 'start'])->name('users.impersonate');
            Route::post('/preview/{role}', [ImpersonationController::class, 'preview'])->name('preview');
            Route::get('/settings/{section?}', [SettingsController::class, 'show'])->defaults('section', 'general')->name('settings.show');
            Route::put('/settings/{section}', [SettingsController::class, 'update'])->name('settings.update');
            Route::post('/settings/email/test', [SettingsController::class, 'testEmail'])->middleware('throttle:6,1')->name('settings.test-email');
            Route::post('/settings/network/test', [SettingsController::class, 'testNetwork'])->middleware('throttle:10,1')->name('settings.test-network');
            Route::post('/settings/categories/test', [SettingsController::class, 'testCategories'])->middleware('throttle:10,1')->name('settings.test-categories');
            Route::post('/settings/network/discover', [SettingsController::class, 'discover'])->middleware('throttle:10,1')->name('settings.discover');
            Route::post('/probes/{profile}/reserve', [ProbeSetupController::class, 'reserve'])->middleware('throttle:10,1')->name('probes.reserve');
            Route::post('/probes/{profile}/outside-pool', [ProbeSetupController::class, 'useOutsidePool'])->name('probes.outside-pool');
            Route::post('/probes/{profile}/review', [ProbeSetupController::class, 'review'])->name('probes.review');
            Route::get('/audit', AuditLogController::class)->name('audit');
            Route::get('/diagnostics', DiagnosticsController::class)->name('diagnostics');
            Route::redirect('/integrations', '/admin/settings/network')->name('integrations.index');
        });
    });
});
