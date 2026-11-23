<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RunManualPush;
use App\Models\PolicyEntry;
use App\Models\PolicyRelease;
use App\Services\Approval\TwoPersonRule;
use App\Services\AuditLogger;
use App\Services\Policy\ControllerPusher;
use App\Services\Policy\PolicyPublisher;
use App\Services\Requests\RequestNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * One page for policy changes: what is waiting for a second IT officer,
 * what publishing now would change in the controller, and every published
 * version with its rollback.
 */
class ChangesController extends Controller
{
    public function index(PolicyPublisher $publisher, ControllerPusher $pusher): View
    {
        return view('admin.policy.changes', [
            'plan' => $publisher->plan(),
            'awaiting' => $publisher->awaitingSecondOfficer(),
            'releases' => PolicyRelease::query()->whereNotNull('number')->orWhere('status', 'failed')->with(['user', 'pushes'])->latest('id')->paginate(20),
            'canPublish' => $pusher->enabled(),
            'publishStatus' => Cache::get(RunManualPush::statusKey('release')),
        ]);
    }

    public function show(PolicyRelease $release): View
    {
        return view('admin.policy.release', ['release' => $release->load(['pushes.filterPolicy', 'user', 'rollbackOf']), 'rolledBackBy' => $release->rolledBackBy()]);
    }

    public function publish(Request $request): RedirectResponse
    {
        $result = RunManualPush::start('release', null, $request->user());

        return RunManualPush::flash(back(), $result, 'Publishing every waiting change as a new policy version. It runs in the background; refresh in a minute to see it under Versions.');
    }

    public function rollback(Request $request, PolicyRelease $release): RedirectResponse
    {
        if (! $release->canRollBack()) {
            return back()->with('error', "{$release->label()} cannot be rolled back.");
        }
        $result = RunManualPush::start('rollback', $release->id, $request->user());

        return RunManualPush::flash(back(), $result, "Rolling back {$release->label()} in the background; refresh in a minute.");
    }

    /** A second IT officer confirms or turns down proposed changes to site-wide lists. */
    public function decide(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'entries' => ['required', 'array', 'max:5000'], 'entries.*' => ['integer'],
            'decision' => ['required', 'in:confirm,reject'],
            'override' => ['nullable', 'boolean'],
            'override_reason' => ['nullable', 'required_if_accepted:override', 'string', 'max:1000'],
        ], ['override_reason.required_if_accepted' => 'Say why no other IT officer is available.']);
        $rule = app(TwoPersonRule::class);
        $override = $validated['decision'] === 'confirm' && $request->boolean('override');
        if ($override && ! $rule->overrideAllowed()) {
            return back()->with('error', 'Confirming your own changes is turned off (Settings → Approval policy).');
        }
        $user = $request->user();
        $entries = PolicyEntry::query()->pending()->whereKey($validated['entries'])->with('policyList')->get();
        $own = $entries->where('proposed_by_id', $user->id);
        $others = $entries->where('proposed_by_id', '!=', $user->id);
        $ownSummary = $own->map(fn (PolicyEntry $entry) => "{$entry->pending_action} {$entry->domain}")->join(', ');

        // The proposer may withdraw their own proposal, but only another officer can confirm it.
        // With the override, the proposer confirms their own changes too (the other officers are told).
        $decided = $validated['decision'] === 'confirm' && ! $override ? $others : $entries;
        foreach ($decided as $entry) {
            $confirm = $validated['decision'] === 'confirm';
            $action = $entry->pending_action;
            $proposer = $entry->proposed_by_id;
            match (true) {
                $confirm && $entry->pending_action === 'remove', ! $confirm && $entry->pending_action === 'add' => $entry->delete(),
                default => $entry->forceFill(['pending_action' => null, 'proposed_by_id' => null, 'proposed_at' => null])->save(),
            };
            $audit->log($confirm ? 'policy.proposal_confirmed' : 'policy.proposal_rejected', $entry->policyList, [
                'domain' => $entry->domain, 'action' => $action, 'proposed_by' => $proposer,
            ]);
        }

        if ($override && $own->isNotEmpty()) {
            $audit->log('policy.two_person_override', null, ['domains' => $own->pluck('domain')->all(), 'reason' => $validated['override_reason']]);
            app(RequestNotifier::class)->twoPersonOverride($user, 'site-wide list changes: '.$ownSummary,
                ['site-wide change'], (string) $validated['override_reason'], route('admin.policy.changes.index'));
            $own = collect();
        }
        $message = $validated['decision'] === 'confirm'
            ? $decided->count().' change(s) confirmed. Publish them to put them in the controller.'
            : $decided->count().' proposal(s) turned down.';
        if ($validated['decision'] === 'confirm' && $own->isNotEmpty()) {
            $message .= ' '.$own->count().' you proposed yourself were left for another IT officer.';
        }

        return back()->with($decided->isEmpty() ? 'error' : 'success', $message);
    }
}
