<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntegrationConfig;
use App\Services\Network\NetworkProviderManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Throwable;

class IntegrationController extends Controller
{
    public function index(NetworkProviderManager $providers): View
    {
        $provider = null;
        try {
            $provider = $providers->current();
        } catch (Throwable) {
        }

        return view('admin.integrations', ['integrations' => IntegrationConfig::query()->get(), 'capabilities' => $provider?->capabilities() ?? []]);
    }

    public function test(NetworkProviderManager $providers): RedirectResponse
    {
        try {
            $result = $providers->current()->test();
            $integration = $providers->integration();
            $integration?->update(['last_tested_at' => now(), 'last_test_status' => $result['ok'] ? 'ok' : 'failed', 'last_test_message' => $result['message']]);

            return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Connection test failed. Check the application log for details.');
        }
    }
}
