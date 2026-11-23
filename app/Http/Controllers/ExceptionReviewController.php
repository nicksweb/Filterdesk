<?php

namespace App\Http\Controllers;

use App\Models\PolicyEntry;
use App\Services\Policy\ExceptionReviews;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The review queue. IT sees every exception due; anyone else sees the ones
 * they own and can keep them. Removing an exception is IT's (through the
 * list, so site-wide removals still need a second officer).
 */
class ExceptionReviewController extends Controller
{
    public function index(Request $request, ExceptionReviews $reviews): View
    {
        $user = $request->user();

        return view('reviews', [
            'entries' => $reviews->due($user->isIt() ? null : $user)->paginate(100),
            'stale' => $user->isIt() ? PolicyEntry::query()->reviewable()->whereNull('pending_action')
                ->where(fn ($query) => $query->where('last_seen_at', '<', now()->subYear())->orWhere(fn ($query) => $query->whereNull('last_seen_at')->where('created_at', '<', now()->subYear())))
                ->with('policyList')->orderBy('domain')->limit(200)->get() : collect(),
            'months' => PolicyEntry::reviewMonths(),
            'grace' => PolicyEntry::graceDays(),
        ]);
    }

    public function keep(Request $request, ExceptionReviews $reviews): RedirectResponse
    {
        $validated = $request->validate(['entries' => ['required', 'array', 'max:1000'], 'entries.*' => ['integer']]);
        $user = $request->user();
        $entries = PolicyEntry::query()->reviewable()->whereKey($validated['entries'])->with('policyList')
            ->when(! $user->isIt(), fn ($query) => $query->where('owner_id', $user->id))->get();

        $kept = $reviews->keep($entries, $user);

        return back()->with('success', "{$kept} exception(s) kept until ".now()->addMonths(PolicyEntry::reviewMonths())->local()->format('j M Y').'.');
    }
}
