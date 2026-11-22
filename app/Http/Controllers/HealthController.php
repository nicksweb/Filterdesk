<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Unauthenticated liveness for monitors: no configuration details are exposed. */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');
            $database = true;
        } catch (Throwable) {
            $database = false;
        }

        return response()->json([
            'status' => $database ? 'ok' : 'degraded',
            'database' => $database ? 'ok' : 'unavailable',
            'version' => config('version.app'),
        ], $database ? 200 : 503)->header('Cache-Control', 'no-store');
    }
}
