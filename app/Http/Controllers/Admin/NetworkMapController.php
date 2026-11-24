<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RunManualPush;
use App\Models\FilterPolicy;
use App\Models\NetworkAssignment;
use App\Models\NetworkType;
use App\Services\AuditLogger;
use App\Services\Network\ProviderRequestFailed;
use App\Services\Policy\BaselineGuard;
use App\Services\Policy\ControllerImporter;
use App\Services\Policy\ControllerLearner;
use App\Services\Policy\ControllerPusher;
use App\Services\Policy\ControllerSync;
use App\Services\Policy\PolicyEngine;
use App\Services\Policy\SyncChecker;
use App\Services\SettingsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
            'unfiltered' => $unfiltered = $baseline->unfiltered($state),
            'learned' => $learned = app(ControllerLearner::class)->learn($state),
            'rows' => $this->rows($networks, $learned, $unfiltered, $baseline->policy()),
            'filterRoles' => $learned['filters'],
            'baselinePolicy' => $baseline->policy(),
        ]);
    }

    /**
     * One row per network: the filters it has in UniFi, its kind, the policy
     * it belongs to here, and one plain status with the action that fixes it.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(Collection $networks, array $learned, Collection $unfiltered, ?FilterPolicy $baselinePolicy): array
    {
        $baselineName = $baselinePolicy?->name ?? 'Students';
        $byNetwork = $unfiltered->keyBy(fn (array $item) => $item['network']->provider_network_id);
        $rows = [];
        foreach ($networks as $network) {
            $lesson = $learned['networks'][$network['id']] ?? null;
            $baselineItem = $byNetwork->get($network['id']);
            $advice = $lesson['advice'] ?? null;
            $status = match (true) {
                ($baselineItem['state'] ?? null) === 'allowed' => ['level' => 'muted', 'text' => 'Allowed unfiltered: '.$baselineItem['network']->unfiltered_reason, 'actions' => ['stop_allowing']],
                ($baselineItem['state'] ?? null) === 'paused' => ['level' => 'bad', 'text' => 'Unfiltered: '.implode(', ', $baselineItem['paused']).(count($baselineItem['paused']) === 1 ? ' is' : ' are').' paused in UniFi.', 'actions' => ['protect', 'allow']],
                ($baselineItem['state'] ?? null) === 'decide' => ['level' => 'warn', 'text' => "No filter. Add {$baselineName}, or allow it unfiltered and say why.", 'actions' => ['protect', 'allow']],
                ($baselineItem['state'] ?? null) === 'enforce' => ['level' => 'bad', 'text' => "No filter: gets {$baselineName} at the next check.", 'actions' => ['protect', 'allow']],
                ($advice['action'] ?? null) === 'remap' => ['level' => 'warn', 'text' => $advice['text'], 'actions' => ['remap'], 'policy' => $advice['policy']],
                $advice !== null => ['level' => $advice['level'] === 'warn' ? 'warn' : 'info', 'text' => $advice['text'], 'actions' => $advice['action'] ? [$advice['action']] : [],
                    'filter_id' => $advice['filter_id'] ?? null, 'filter_name' => $advice['filter_name'] ?? null],
                in_array($network['status'], ['missing_filter', 'wrong_filter', 'policy_unlinked', 'layer_missing'], true) => ['level' => 'warn', 'text' => match ($network['status']) {
                    'missing_filter' => 'Mapped to '.$network['policy']->name.' here, but its filter is not on this network in UniFi.',
                    'wrong_filter' => 'Mapped to '.$network['policy']->name.' here, but UniFi uses other filters.',
                    'layer_missing' => 'Missing the layer '.($network['layer']->provider_name ?: $network['layer']->name).', so its feeds are not blocked here.',
                    default => $network['policy']->name.' is not linked to a UniFi filter yet.',
                }, 'actions' => []],
                $network['status'] === 'hours_only' => ['level' => 'info', 'text' => 'Filtered as '.$network['policy']->name.' only in school hours ('.($network['hours']->provider_name ?: $network['hours']->name).'). Add '.($network['policy']->provider_name ?: $network['policy']->name).' to filter it at every hour.',
                    'actions' => ['add_filter'], 'filter_id' => $network['policy']->provider_reference, 'filter_name' => $network['policy']->provider_name ?: $network['policy']->name],
                $network['status'] === 'multiple_filters' => ['level' => 'info', 'text' => 'Also in '.collect($network['others'])->pluck('name')->join(', ').': a site '.($network['policy']->provider_name ?: $network['policy']->name).' allows can still be blocked there.', 'actions' => []],
                default => ['level' => 'ok', 'text' => $lesson && $lesson['types'] ? collect($lesson['types'])->map(fn ($type) => NetworkType::labelFor($type))->join(' and ').' filtering' : 'Filtered', 'actions' => []],
            };
            $rows[] = [
                'id' => $network['id'], 'assignment' => $network['assignment'], 'name' => $network['name'], 'vlan' => $network['vlan'], 'subnet' => $network['subnet'] ?? null,
                'kind' => $lesson['kind'] ?? 'device', 'kind_confirmed' => $lesson['kind_confirmed'] ?? false, 'policy' => $network['policy'],
                'filters' => collect($network['attached'])->map(fn (array $filter) => [
                    'id' => $filter['id'], 'name' => $filter['name'], 'paused' => ! ($filter['enabled'] ?? true),
                    'role' => $learned['filters'][$filter['id']]['role'] ?? 'group',
                    'when' => ($learned['filters'][$filter['id']]['scheduled'] ?? false) ? $learned['filters'][$filter['id']]['when'] : null,
                ])->sortBy(fn (array $filter) => [$filter['role'] === 'sitewide' ? 1 : 0, $filter['name']])->values()->all(),
                'status' => $status,
                'attention' => in_array($status['level'], ['warn', 'bad'], true),
                'unfiltered' => $network['attached'] === [] || collect($network['attached'])->every(fn (array $filter) => ! ($filter['enabled'] ?? true)),
            ];
        }

        return collect($rows)->sortBy(fn (array $row) => [$row['attention'] ? 0 : 1, $row['vlan'] ?? 0])->values()->all();
    }

    /** Give unfiltered networks the baseline filter now: all that are due, or the ones picked. */
    public function baseline(Request $request, BaselineGuard $baseline): RedirectResponse
    {
        $validated = $request->validate(['networks' => ['nullable', 'array', 'max:200'], 'networks.*' => ['string', 'max:190']]);
        $result = $baseline->enforce($request->user(), $validated['networks'] ?? null);

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /** Put a UniFi content filter on this network, or take one off it. */
    public function filter(Request $request, NetworkAssignment $network, ControllerSync $sync, ControllerPusher $pusher, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate(['filter_id' => ['required', 'string', 'max:190'], 'remove' => ['nullable', 'boolean']]);
        $filter = collect($sync->state()['filters'])->firstWhere('id', $validated['filter_id']);
        if (! $filter) {
            return back()->with('error', 'That content filter is no longer in UniFi. Refresh and try again.');
        }
        if (! $pusher->enabled() || ! $pusher->writer()) {
            return back()->with('error', 'Changing which filters a network has needs the network provider in Active mode (Settings → Network provider). Or change it in UniFi → Content Filter.');
        }
        $remove = $request->boolean('remove');
        try {
            $remove
                ? $pusher->writer()->detachNetworksFromContentFilter($filter['id'], [$network->provider_network_id])
                : $pusher->writer()->attachNetworksToContentFilter($filter['id'], [$network->provider_network_id]);
        } catch (ProviderRequestFailed $exception) {
            return back()->with('error', $exception->getMessage());
        }
        // Taking off the filter of the policy this network is mapped to leaves it unmapped.
        if ($remove && $network->filterPolicy?->provider_reference === $filter['id']) {
            $network->update(['filter_policy_id' => null]);
        }
        $sync->forget();
        $audit->log($remove ? 'policy.network_filter_removed' : 'policy.network_filter_added', $network->filterPolicy, ['network' => $network->name, 'filter' => $filter['name']]);

        return back()->with('success', $remove ? "{$filter['name']} is no longer on {$network->name}." : "{$filter['name']} now filters {$network->name}.");
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
        // One control: "policy:<id>" (an existing policy), "new:<type>" (a new policy of that type) or "sitewide".
        $managedBy = (string) $request->input('managed_by', '');
        if (preg_match('/^policy:(\d+)$/', $managedBy, $match)) {
            $request->merge(['filter_policy_id' => (int) $match[1]]);
        } elseif (preg_match('/^new:([a-z0-9_]+)$/', $managedBy, $match)) {
            $request->merge(['audience' => $match[1]]);
        } elseif (preg_match('/^hours:(\d+)$/', $managedBy, $match)) {
            return $this->linkHours($request, (int) $match[1], $sync, $audit);
        } elseif ($managedBy === 'sitewide') {
            $request->merge(['audience' => NetworkType::byKey('other') ? 'other' : NetworkType::query()->ordered()->value('key'), 'sitewide' => true]);
        }
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
        if ($request->boolean('sitewide')) {
            $policy->update(['role' => 'sitewide']);
        }

        $pulled = $sync->link($filter, $policy->refresh(), $request->user());
        $audit->log('policy.filter_linked', $policy, ['filter' => $filter['name']]);

        return back()->with('success', "{$filter['name']} is linked to {$policy->name}. ".$pulled['message']);
    }

    /**
     * Link a scheduled controller filter as the school-hours layer of a
     * policy: it keeps its allows and blocks and UniFi's schedule, and gets
     * the policy's allows too, so approvals work in school hours as well.
     */
    private function linkHours(Request $request, int $parentId, ControllerSync $sync, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate(['filter_id' => ['required', 'string', 'max:190']]);
        $filter = collect($sync->state()['filters'])->firstWhere('id', $validated['filter_id']);
        $parent = FilterPolicy::query()->main()->whereKey($parentId)->first();
        if (! $filter || ! $parent) {
            return back()->with('error', 'That content filter or policy no longer exists. Refresh and try again.');
        }
        if ($parent->provider_reference === $filter['id']) {
            return back()->with('error', 'A filter cannot be the school-hours layer of its own policy.');
        }
        $importer = app(ControllerImporter::class);
        $layer = $importer->hoursLayer($parent, $filter);
        $imported = $layer->wasRecentlyCreated ? $importer->importEntries($layer, $filter, $request->user()) : 0;
        $sync->link($filter, $layer->refresh(), $request->user());
        $audit->log('policy.filter_linked', $layer, ['filter' => $filter['name'], 'as' => 'hours', 'parent' => $parent->name]);

        return back()->with('success', "{$filter['name']} is now the school-hours layer of {$parent->name}. It keeps its ".count($filter['allow']).' allowed and '.count($filter['block']).' blocked sites and UniFi\'s schedule'
            .($imported ? ' ('.number_format($imported).' entries brought in)' : '')."; {$parent->name}'s allows go into it too, so approvals also work in school hours.");
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
