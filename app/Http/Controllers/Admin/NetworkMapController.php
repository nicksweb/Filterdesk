<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RunManualPush;
use App\Models\FilterPolicy;
use App\Models\NetworkAssignment;
use App\Services\AuditLogger;
use App\Services\Policy\BaselineGuard;
use App\Services\Policy\ControllerLearner;
use App\Services\Policy\ControllerPusher;
use App\Services\Policy\ControllerSync;
use App\Services\Policy\PolicyEngine;
use App\Services\Policy\SyncChecker;
use App\Services\SettingsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Networks & filters: which controller content filter is attached to each
 * VLAN, which filter policy (and so staff, student or guest filtering) each
 * VLAN should have, and where the two disagree.
 */
class NetworkMapController extends Controller
{
    public function index(Request $request, ControllerSync $sync, ControllerPusher $pusher, BaselineGuard $baseline): View
    {
        $state = $sync->state($request->boolean('refresh'));
        $policies = FilterPolicy::query()->with('layerOf')->ordered()->get();
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
            'pushStatus' => Cache::get(RunManualPush::statusKey('all')),
            'policies' => $policies->reject(fn (FilterPolicy $policy) => $policy->isLayer())->values(),
            'unfiltered' => $baseline->unfiltered($state),
            'learned' => app(ControllerLearner::class)->learn($state)['networks'],
            'baselinePolicy' => $baseline->policy(),
        ]);
    }

    /** Give unfiltered networks the baseline filter now: all that are due, or the ones picked. */
    public function baseline(Request $request, BaselineGuard $baseline): RedirectResponse
    {
        $validated = $request->validate(['networks' => ['nullable', 'array', 'max:200'], 'networks.*' => ['string', 'max:190']]);
        $result = $baseline->enforce($request->user(), $validated['networks'] ?? null);

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /** Add networks to the Students (baseline) filter: protection for device networks, or a user network missing its group. */
    public function protect(Request $request, BaselineGuard $baseline): RedirectResponse
    {
        $validated = $request->validate(['networks' => ['required', 'array', 'max:200'], 'networks.*' => ['string', 'max:190']]);
        $result = $baseline->protect($validated['networks'], $request->user());

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /** Confirm a network is a user or device network (or go back to the learned guess). */
    public function kind(Request $request, NetworkAssignment $network, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate(['kind' => ['required', 'in:user,device,auto']]);
        $network->update(['role' => $validated['kind'] === 'auto' ? null : $validated['kind']]);
        $audit->log('policy.network_kind_set', null, ['network' => $network->name, 'kind' => $validated['kind']]);

        return back()->with('success', "{$network->name}: ".match ($validated['kind']) {
            'user' => 'user network', 'device' => 'device network', default => 'learned from UniFi again'
        }.'.');
    }

    /** Allow a network to stay unfiltered (a reason is required), or take that back. */
    public function allowUnfiltered(Request $request, NetworkAssignment $network, BaselineGuard $baseline): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'required_unless:allow,0', 'string', 'max:255'], 'allow' => ['required', 'boolean']]);
        $baseline->allow($network, $request->boolean('allow') ? $validated['reason'] : null, $request->user());

        return back()->with('success', $request->boolean('allow')
            ? "{$network->name} may stay unfiltered: {$validated['reason']}."
            : "{$network->name} is no longer exempt. It gets baseline filtering at the next check, or use Filter it now.");
    }

    /** Which policy's filter unfiltered networks join. */
    public function baselinePolicy(Request $request, SettingsRepository $settings, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate(['policy_id' => ['required', 'integer', Rule::exists('filter_policies', 'id')->whereNull('layer_of_id')->whereNotNull('provider_reference')]]);
        $settings->set(BaselineGuard::POLICY_KEY, (int) $validated['policy_id']);
        $policy = FilterPolicy::query()->findOrFail($validated['policy_id']);
        $audit->log('policy.baseline_policy_set', $policy);

        return back()->with('success', "Unfiltered networks now get {$policy->name} filtering.");
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
            'audience' => ['nullable', 'required_without:filter_policy_id', Rule::exists('network_types', 'key')],
        ]);

        $filter = collect($sync->state()['filters'])->firstWhere('id', $validated['filter_id']);
        if (! $filter) {
            return back()->with('error', 'That content filter is no longer in the controller. Refresh and try again.');
        }

        if (filled($validated['filter_policy_id'] ?? null)) {
            $policy = FilterPolicy::query()->findOrFail($validated['filter_policy_id']);
        } else {
            $audience = $validated['audience'];
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

        if (FilterPolicy::query()->whereNotNull('provider_reference')->doesntExist()) {
            return back()->with('error', 'No policy is linked to a controller content filter yet.');
        }
        $result = RunManualPush::start('all', null, $request->user());

        return RunManualPush::flash(back(), $result, 'Pushing every linked policy. It runs in the background; the result appears in the push history in a minute or so.');
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
