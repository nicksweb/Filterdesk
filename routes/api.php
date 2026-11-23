<?php

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\MetricsController;
use Illuminate\Support\Facades\Route;

/*
 * API v1. Bearer tokens are created under Settings → Integrations; each acts
 * as the IT officer who created it, with the abilities chosen.
 */
Route::prefix('v1')->middleware(['installed', 'throttle:120,1'])->group(function (): void {
    Route::middleware('api.token:read')->group(function (): void {
        Route::get('/requests', [ApiController::class, 'requests']);
        Route::get('/requests/{reference}', [ApiController::class, 'show']);
        Route::get('/versions', [ApiController::class, 'versions']);
        Route::get('/explain', [ApiController::class, 'explain']);
        Route::get('/metrics', MetricsController::class);
    });
    Route::post('/requests', [ApiController::class, 'store'])->middleware('api.token:requests');
    Route::post('/requests/{reference}/decision', [ApiController::class, 'decide'])->middleware('api.token:decide');
});
