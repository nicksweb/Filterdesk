<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Audience;
use App\Http\Controllers\Controller;
use App\Models\FilterPolicy;
use App\Models\NetworkAssignment;
use App\Services\AuditLogger;
use App\Services\Policy\ControllerPusher;
use App\Services\Policy\ControllerSync;
use App\Services\Policy\PolicyEngine;
use App\Services\Policy\SyncChecker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Networks & filters: which controller content filter is attached to each
 * VLAN, which filter policy (and so staff, student or guest filtering) each
 * VLAN should have, and where the two disagree.
 */
class NetworkMapController extends Controller
{
    public function index(Request $request, ControllerSync $sync, ControllerPusher $pusher): View
    {
        $state = $sync->state($request->boolean('refresh'));
        $policies = FilterPolicy::query()->ordered()->get();
        $networks = $state['error'] ? collect() : $sync->networkReport($state);

        return view('admin.policy.networks', [
            'state' => $state,
            'available' => $sync->available(),
            'canPush' => $pusher->enabled(),
            'networks' => $networks,
            'stored' => $state['error'] ? NetworkAssignment::query()->with('filterPolicy')->orderBy('vlan')->orderBy('name')->get() : collect(),
            'filters' => collect($state['filters'])->map(fn (array $filter) => $filter + [
                'policy' => $policies->first(fn (FilterPolicy $policy) => $policy->provider_reference === $filter['id']),
                'network_names' => $networks->whereIn('id', $filter['network_ids'])->map(fn (array $network) => $network['name'].($network['vlan'] ? " (VLAN {$network['vlan']})" : ''))->values()->all(),
            ]),
            'policies' => $policies,
        ]);
    }

    /** Save which filter policy each VLAN should have. */
    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'assignments' => ['array'],
            'assignments.*' => ['nullable', 'integer', 'exists:filter_policies,id'],
        ]);

        $changed = 0;
        foreach ($validated['assignments'] ?? [] as $networkId => $policyId) {
            $assignment = NetworkAssignment::query()->where('provider_network_id', (string) $networkId)->first();
            if (! $assignment) {
                continue;
            }
            $assignment->filter_policy_id = $policyId ?: null;
            if ($assignment->isDirty()) {
                $assignment->save();
                $changed++;
            }
        }
        $audit->log('policy.networks_mapped', null, ['changed' => $changed]);

        return back()->with('success', "{$changed} network mapping(s) updated.");
    }

    /**
     * Link a controller content filter to a filter policy, creating the
     * policy when asked so a VLAN's filter can be managed in one step.
     */
    public function link(Request $request, ControllerSync $sync, PolicyEngine $engine, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'filter_id' => ['required', 'string', 'max:190'],
            'filter_policy_id' => ['nullable', 'required_without:audience', 'integer', 'exists:filter_policies,id'],
            'audience' => ['nullable', 'required_without:filter_policy_id', Rule::enum(Audience::class)],
        ]);

        $filter = collect($sync->state()['filters'])->firstWhere('id', $validated['filter_id']);
        if (! $filter) {
            return back()->with('error', 'That content filter is no longer in the controller. Refresh and try again.');
        }

        if (filled($validated['filter_policy_id'] ?? null)) {
            $policy = FilterPolicy::query()->findOrFail($validated['filter_policy_id']);
        } else {
            $audience = Audience::from($validated['audience']);
            $policy = FilterPolicy::query()->create([
                'name' => $filter['name'], 'slug' => $engine->uniqueSlug($filter['name']), 'audience' => $audience,
                'description' => "Linked to the controller content filter {$filter['name']}.",
            ]);
            $engine->ensureAudienceLists($audience);
        }

        $pulled = $sync->link($filter, $policy, $request->user());
        $audit->log('policy.filter_linked', $policy, ['filter' => $filter['name']]);

        return back()->with('success', "{$filter['name']} is linked to {$policy->name}. ".$pulled['message']);
    }

    /** Push every linked policy's pending changes. */
    public function pushAll(Request $request, ControllerPusher $pusher): RedirectResponse
    {
        if (! $pusher->enabled()) {
            return back()->with('error', 'Pushing is off. An admin can turn it on under Settings → Network provider (Mode: Active).');
        }

        $results = $pusher->pushAll($request->user());
        if ($results->isEmpty()) {
            return back()->with('error', 'No policy is linked to a controller content filter yet.');
        }

        return back()->with($results->every(fn (array $result) => $result['ok']) ? 'success' : 'error', $results->pluck('message')->join(' '));
    }

    /** Compare every linked policy with its live controller filter now. */
    public function checkSync(SyncChecker $checker): RedirectResponse
    {
        $policies = $checker->checkAll(notify: false);
        if ($policies->isEmpty()) {
            return back()->with('error', 'No policy is linked to a controller content filter yet.');
        }
        $off = $policies->reject(fn ($policy) => $policy->sync_status === 'in_sync');

        return back()->with($off->isEmpty() ? 'success' : 'error', $off->isEmpty()
            ? 'Every linked filter matches its policy.'
            : $off->map(fn ($policy) => "{$policy->name}: {$policy->sync_details['summary']}")->join(' '));
    }

    /** Fetch every linked policy's lists from the controller in one go. */
    public function pullAll(Request $request, ControllerSync $sync, AuditLogger $audit): RedirectResponse
    {
        $sync->forget();
        $messages = [];
        $failed = false;
        foreach (FilterPolicy::query()->where('enabled', true)->whereNotNull('provider_reference')->ordered()->get() as $policy) {
            $result = $sync->pull($policy, $request->user(), refresh: false);
            $failed = $failed || ! $result['ok'];
            $messages[] = $result['message'];
        }
        $audit->log('policy.snapshots_pulled', null, ['count' => count($messages)]);
        app(SyncChecker::class)->checkAll(notify: false);

        if ($messages === []) {
            return back()->with('error', 'No policy is linked to a controller content filter yet.');
        }

        return back()->with($failed ? 'error' : 'success', implode(' ', $messages));
    }
}
