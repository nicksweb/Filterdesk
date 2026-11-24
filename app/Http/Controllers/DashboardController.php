<?php

namespace App\Http\Controllers;

use App\Enums\RequestStatus;
use App\Models\FilterPolicy;
use App\Models\NetworkProfile;
use App\Models\PolicyEntry;
use App\Models\PolicyRelease;
use App\Models\WebsiteCheck;
use App\Models\WebsiteRequest;
use App\Services\Coverage;
use App\Services\Network\NetworkProviderManager;
use App\Services\Network\ReadsContentFilters;
use App\Services\Reports\PolicyInsights;
use App\Services\SetupChecklist;
use App\Services\Website\ProbeDiagnostics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            // Probes that need setup, from the last checklist run (never asks the probes here).
            'probesNeedingWork' => $user->isAdmin() ? NetworkProfile::query()->orderBy('sort_order')->get()
                ->map(fn (NetworkProfile $profile) => ['profile' => $profile, 'diagnosis' => app(ProbeDiagnostics::class)->cached($profile)])
                ->filter(fn (array $item) => $item['diagnosis'] ? in_array($item['diagnosis']['state'], ['fail', 'todo'], true) && ($item['profile']->enabled || $item['diagnosis']['state'] === 'todo') : $item['profile']->enabled && blank($item['profile']->unifi_network_id))
                ->values() : collect(),
            'recentChecks' => WebsiteCheck::query()->where('user_id', $user->id)->latest()->limit(5)->get(),
            // Role homes: IT managers (admins) see coverage and suggestions; IT officers see what waits on them; everyone sees their reviews.
            'setup' => $user->isAdmin() && app(SetupChecklist::class)->visible() ? app(SetupChecklist::class)->items() : [],
            'setupHidden' => $user->isAdmin() && app(SetupChecklist::class)->hiddenWithStepsLeft(),
            'coverage' => $user->isAdmin() ? app(Coverage::class)->report() : null,
            'insights' => $user->isAdmin() ? array_slice(rescue(fn () => app(PolicyInsights::class)->insights(...array_values(array_slice(app(PolicyInsights::class)->defaultPeriod(), 0, 2))), [], false), 0, 3) : [],
            'secondApprovals' => $user->isIt() ? WebsiteRequest::query()->where('status', RequestStatus::AwaitingSecondApproval)->where('first_approval->user_id', '!=', $user->id)->count()
                + PolicyEntry::query()->pending()->where('proposed_by_id', '!=', $user->id)->count() : 0,
            'publishedToday' => $user->isIt() ? PolicyRelease::query()->whereNotNull('number')->where('created_at', '>=', today())->latest('id')->limit(5)->get() : collect(),
            'myReviews' => PolicyEntry::query()->reviewable()->where('owner_id', $user->id)->where('review_at', '<=', now()->addDays(PolicyEntry::graceDays()))->count(),
        ]);
    }

    /** Mark a setup item done, or hide the whole checklist. */
    public function setup(Request $request, SetupChecklist $checklist): RedirectResponse
    {
        $validated = $request->validate(['item' => ['required', 'string', 'max:40'], 'undo' => ['nullable', 'boolean']]);
        match (true) {
            $validated['item'] === 'all' => $checklist->hide(),
            $validated['item'] === 'show' => $checklist->show(),
            $request->boolean('undo') => $checklist->undo($validated['item']),
            default => $checklist->markDone($validated['item']),
        };

        return back();
    }
}
