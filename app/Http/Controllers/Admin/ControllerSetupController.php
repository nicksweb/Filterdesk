<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Audience;
use App\Http\Controllers\Controller;
use App\Models\FilterPolicy;
use App\Models\NetworkProfile;
use App\Services\AuditLogger;
use App\Services\Policy\ControllerImporter;
use App\Services\Policy\ControllerSync;
use App\Services\Policy\ProbePlanner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Guided setup from a connected controller: bring every content filter in
 * as a filter policy with its own lists, map the networks it applies to, and
 * recommend a probe container per policy so checks match real filtering.
 */
class ControllerSetupController extends Controller
{
    public function show(Request $request, ControllerSync $sync, ProbePlanner $planner, ControllerImporter $importer): View
    {
        $state = $sync->state($request->boolean('refresh'));
        $policies = FilterPolicy::query()->with('layerOf', 'networkProfile')->ordered()->get();
        $networks = $state['error'] ? collect() : $sync->networkReport($state);
        $suggestions = $state['error'] ? [] : $importer->suggest($state);
        $parent = (string) $request->query('parent', 'eth0');
        $extra = $this->extraNetworks($request);
        $plans = $planner->plan($parent, $extra);
        $filterNames = collect($state['filters'])->pluck('name', 'id');

        return view('admin.policy.setup', [
            'state' => $state,
            'filters' => collect($state['filters'])->map(fn (array $filter) => $filter + [
                'linked' => $policies->firstWhere('provider_reference', $filter['id']),
                'suggestion' => $suggestions[$filter['id']] ?? ['action' => 'create', 'policy_id' => null, 'audience' => Audience::Other->value, 'import' => true, 'parent' => null],
                'parent_name' => $filterNames[$suggestions[$filter['id']]['parent'] ?? ''] ?? null,
                'networks' => $networks->whereIn('id', $filter['network_ids'])->values(),
                'shared' => $networks->whereIn('id', $filter['network_ids'])->filter(fn ($network) => in_array($network['status'], ['multiple_filters', 'unmapped'], true) && count($network['attached']) > 1)->values(),
            ]),
            'policies' => $policies->reject(fn (FilterPolicy $policy) => $policy->isLayer())->values(),
            'parentFilters' => collect($state['filters'])->reject(fn (array $filter) => ($suggestions[$filter['id']]['action'] ?? null) === 'layer')->values(),
            'linkedCount' => $policies->filter(fn (FilterPolicy $policy) => filled($policy->provider_reference))->count(),
            'ready' => [
                'policies' => $policies->filter(fn (FilterPolicy $policy) => filled($policy->provider_reference))->values(),
                'mapped' => $networks->whereNotNull('policy')->count(),
                'networks' => $networks->count(),
                'profiles' => NetworkProfile::query()->orderBy('sort_order')->get(),
            ],
            'parent' => $parent,
            'extra' => $extra,
            'extraOptions' => $networks->reject(fn (array $network) => $plans->contains(fn (array $plan) => $plan['network']->provider_network_id === $network['id'] && $plan['policy']))->values(),
            'plans' => $plans,
        ]);
    }

    public function store(Request $request, ControllerSync $sync, ControllerImporter $importer): RedirectResponse
    {
        $validated = $request->validate([
            'filters' => ['required', 'array'],
            'filters.*.action' => ['required', 'in:skip,link,create,layer'],
            'filters.*.policy_id' => ['nullable', 'integer', 'exists:filter_policies,id'],
            'filters.*.audience' => ['nullable', Rule::enum(Audience::class)],
            'filters.*.import' => ['nullable', 'boolean'],
            'filters.*.parent' => ['nullable', 'string', 'max:120'],
        ]);

        $state = $sync->state(true);
        if ($state['error']) {
            return back()->with('error', $state['error']);
        }

        $done = $importer->apply($state, $validated['filters'], $request->user());

        return redirect()->route('admin.policy.setup.show')->withFragment('probes')
            ->with('success', $done ? 'Set up: '.implode('; ', $done).'. Next, set up a probe for each policy below.' : 'Nothing was changed.');
    }

    /**
     * One step: take every suggestion (link the default policies, create the
     * rest, recognise layers, import every entry, map networks), then prepare
     * the probe profiles. Nothing is changed in the controller.
     */
    public function importAll(Request $request, ControllerSync $sync, ControllerImporter $importer, ProbePlanner $planner, AuditLogger $audit): RedirectResponse
    {
        $state = $sync->state(true);
        if ($state['error']) {
            return back()->with('error', $state['error']);
        }

        $done = $importer->apply($state, $importer->suggest($state), $request->user());
        $profiles = $this->prepareProfiles($planner, (string) $request->input('parent', 'eth0'), []);
        $audit->log('policy.setup_imported_all', null, ['filters' => count($done), 'profiles' => $profiles]);

        return redirect()->route('admin.policy.setup.show')->withFragment('ready')
            ->with('success', ($done ? 'Brought in: '.implode('; ', $done).'.' : 'Every filter was already set up.')
                .($profiles ? ' Prepared probe profiles: '.implode(', ', $profiles).'.' : '').' Follow the probe steps below, then enable each profile.');
    }

    /** Create disabled network profiles for the planned probes and link them to their policies. */
    public function profiles(Request $request, ProbePlanner $planner, AuditLogger $audit): RedirectResponse
    {
        $created = $this->prepareProfiles($planner, (string) $request->input('parent', 'eth0'), $this->extraNetworks($request));
        $audit->log('policy.probe_profiles_prepared', null, ['created' => $created]);

        return back()->with('success', ($created ? 'Created disabled probe profiles: '.implode(', ', $created).'. ' : 'Probe profiles are linked. ')
            .'Enable each one under Settings → Network profiles once its container is running.');
    }

    /** The planned compose.vlan.yml for every probe, as a file. */
    public function compose(Request $request, ProbePlanner $planner): Response
    {
        return response($planner->composeFile($planner->plan((string) $request->query('parent', 'eth0'), $this->extraNetworks($request))), 200, [
            'Content-Type' => 'text/yaml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="compose.vlan.yml"',
        ]);
    }

    /**
     * Create (disabled) and link a network profile per planned probe.
     *
     * @param  list<string>  $extra
     * @return list<string> names of profiles created
     */
    private function prepareProfiles(ProbePlanner $planner, string $parent, array $extra): array
    {
        $created = [];
        foreach ($planner->plan($parent, $extra) as $plan) {
            $profile = $plan['profile'] ?? NetworkProfile::query()->firstOrCreate(['key' => $plan['key']], [
                'name' => $plan['label'],
                'audience' => $plan['audience']->value,
                'probe_url' => $plan['probe_url'],
                'enabled' => false,
                'sort_order' => (int) NetworkProfile::query()->max('sort_order') + 1,
            ]);
            $profile->update(['unifi_network_id' => $plan['network']->provider_network_id, 'unifi_policy_id' => $plan['policy']?->provider_reference]);
            if ($plan['policy'] && $plan['policy']->network_profile_id !== $profile->id) {
                $plan['policy']->update(['network_profile_id' => $profile->id]);
            }
            if ($profile->wasRecentlyCreated) {
                $created[] = $profile->name;
            }
        }

        return $created;
    }

    /** @return list<string> network ids picked for extra probes */
    private function extraNetworks(Request $request): array
    {
        return collect((array) $request->input('extra', []))->filter(fn ($id) => is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,80}$/', $id))->values()->all();
    }
}
