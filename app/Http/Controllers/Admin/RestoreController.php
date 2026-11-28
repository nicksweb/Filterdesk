<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RestoreToPoint;
use App\Models\ConfigSnapshot;
use App\Services\AuditLogger;
use App\Services\Policy\ControllerPusher;
use App\Services\Policy\StateHistory;
use App\Services\SettingsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * Changes → Restore to a point in time: choose a date and time, see what
 * each content filter and firewall allow list would go back to, tick the
 * ones to restore, and confirm. Imported IP block lists are not included:
 * they are rebuilt from their feeds.
 */
class RestoreController extends Controller
{
    public function index(Request $request, StateHistory $history, SettingsRepository $settings): View
    {
        $request->validate(['at' => ['nullable', 'date_format:Y-m-d\TH:i']]);
        $timezone = $settings->timezone();
        $when = $request->filled('at') ? Carbon::createFromFormat('Y-m-d\TH:i', $request->string('at')->toString(), $timezone)->utc() : null;
        $latest = $history->latest();
        $rows = $when ? $history->at($when)->map(fn (ConfigSnapshot $snapshot) => [
            'snapshot' => $snapshot,
            'difference' => $difference = StateHistory::difference($snapshot, $latest->get("{$snapshot->kind}:{$snapshot->reference}")),
            'changes' => collect($difference)->sum(fn (array $values) => count($values)),
        ]) : collect();

        return view('admin.restore', [
            'when' => $when?->clone()->setTimezone($timezone),
            'at' => $request->input('at', now($timezone)->subDay()->format('Y-m-d\TH:i')),
            'rows' => $rows,
            'since' => $history->since()?->clone()->setTimezone($timezone),
            'canWrite' => app(ControllerPusher::class)->enabled(),
            'status' => Cache::get(RestoreToPoint::STATUS_KEY),
        ]);
    }

    public function store(Request $request, SettingsRepository $settings, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'at' => ['required', 'date_format:Y-m-d\TH:i'],
            'snapshots' => ['required', 'array', 'min:1', 'max:200'], 'snapshots.*' => ['integer'],
            'understood' => ['accepted'],
        ], ['snapshots.required' => 'Tick at least one filter or list to restore.', 'understood.accepted' => 'Tick the box to confirm.']);
        if (($status = Cache::get(RestoreToPoint::STATUS_KEY)) && $status['state'] === 'running') {
            return back()->with('error', 'A restore is already running.');
        }
        $when = Carbon::createFromFormat('Y-m-d\TH:i', $validated['at'], $settings->timezone());
        // Only versions that really were the ones in force then, whatever the form sent.
        $allowed = app(StateHistory::class)->at($when->clone()->utc())->pluck('id')->all();
        $ids = array_values(array_intersect(array_map('intval', $validated['snapshots']), $allowed));
        if ($ids === []) {
            return back()->with('error', 'Nothing to restore for that time.');
        }
        $label = $when->format('j M Y g:i a');
        $audit->log('policy.restore_started', null, ['at' => $label, 'versions' => $ids]);
        Cache::put(RestoreToPoint::STATUS_KEY, ['state' => 'running', 'when' => $label, 'since' => now()->toIso8601String(), 'results' => []], 3600);
        RestoreToPoint::dispatch($ids, $label, $request->user()->id);

        return redirect()->route('admin.restore.index', ['at' => $validated['at']])->with('success', "Restoring to {$label} in the background. Results appear on this page.");
    }
}
