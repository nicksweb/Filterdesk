<?php

namespace App\Http\Controllers;

use App\Enums\RequestStatus;
use App\Models\FilterPolicy;
use App\Models\WebsiteCheck;
use App\Models\WebsiteRequest;
use App\Services\Network\NetworkProviderManager;
use App\Services\Network\ReadsContentFilters;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $user = auth()->user();
        $requests = WebsiteRequest::query()
            ->when(! $user->isIt(), fn ($query) => $query->where('requester_id', $user->id));

        return view('dashboard', [
            'pending' => (clone $requests)->whereIn('status', RequestStatus::open())->count(),
            'actionRequired' => (clone $requests)->whereIn('status', [RequestStatus::Approved, RequestStatus::ActionRequired, RequestStatus::Failed])->count(),
            'mine' => $user->isIt() ? WebsiteRequest::query()->where('assigned_to_id', $user->id)->whereIn('status', RequestStatus::open())->count() : 0,
            'active' => (clone $requests)->where('status', RequestStatus::Active)->count(),
            'recentRequests' => $requests->with('requester')->latest('updated_at')->limit(6)->get(),
            'filtersOutOfSync' => $user->isIt() ? FilterPolicy::query()->where('enabled', true)->whereIn('sync_status', ['drift', 'error'])->ordered()->get() : collect(),
            // Right after installation: the controller is connected but none of its filters are in yet.
            'needsControllerSetup' => $user->isAdmin() && FilterPolicy::query()->whereNotNull('provider_reference')->doesntExist()
                && rescue(fn () => app(NetworkProviderManager::class)->current() instanceof ReadsContentFilters && app(NetworkProviderManager::class)->integration()?->provider !== 'mock', false, false),
            'recentChecks' => WebsiteCheck::query()->where('user_id', $user->id)->latest()->limit(5)->get(),
        ]);
    }
}
