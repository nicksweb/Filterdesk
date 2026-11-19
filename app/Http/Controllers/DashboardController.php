<?php

namespace App\Http\Controllers;

use App\Enums\RequestStatus;
use App\Models\WebsiteCheck;
use App\Models\WebsiteRequest;
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
            'recentChecks' => WebsiteCheck::query()->where('user_id', $user->id)->latest()->limit(5)->get(),
        ]);
    }
}
