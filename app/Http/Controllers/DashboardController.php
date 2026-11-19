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
            'pending' => (clone $requests)->whereIn('status', [RequestStatus::Pending, RequestStatus::NeedsInformation, RequestStatus::ActionRequired])->count(),
            'active' => (clone $requests)->where('status', RequestStatus::Active)->count(),
            'recentRequests' => $requests->with('requester')->latest()->limit(6)->get(),
            'recentChecks' => WebsiteCheck::query()->where('user_id', $user->id)->latest()->limit(5)->get(),
        ]);
    }
}
