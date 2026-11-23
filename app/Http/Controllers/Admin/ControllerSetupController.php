<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FilterPolicy;
use App\Models\NetworkProfile;
use App\Services\AuditLogger;
use App\Services\Policy\ControllerImporter;
use App\Services\Policy\ControllerSync;
use App\Services\Policy\ProbePlanner;
use App\Services\SettingsRepository;
use App\Services\Website\ProbeDiagnostics;
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
    public function show(Request $request, ControllerSync $sync, ProbePlanner $planner, ControllerImporter $importer, SettingsRepository $settings, ProbeDiagnostics $diagnostics): View
    {
        // Remember whether people reach the app through a reverse proxy, so the
        // COMPOSE_FILE line (here and from probes:compose) includes compose.proxy.yml.
        $proxy = $this->behindProxy($request);
        if ((bool) $settings->get('probes.behind_proxy', false) !== $proxy) {
            $settings->set('probes.behind_proxy', $proxy);
        }
        $state = $sync->state($request->boolean('refresh'));
        $policies = FilterPolicy::query()->with('layerOf', 'networkProfile')->ordered()->get();
        $networks = $state['error'] ? collect() : $sync->networkReport($state);
        $suggestions = $state['error'] ? [] : $importer->suggest($state);
        $saved = $planner->saved();
        $parent = $this->parentOverride($request) ?? $saved['trunk'];
        $extra = $this->extraNetworks($request) ?? $saved['extra'];
        $plans = $planner->plan($parent, $extra);
        $filterNames = collect($state['filters'])->pluck('name', 'id');
        $linked = $policies->filter(fn (FilterPolicy $policy) => filled($policy->provider_reference));
        $profiles = NetworkProfile::query()->orderBy('sort_order')->get();
        $probeProfiles = $profiles->filter(fn (NetworkProfile $profile) => $plans->contains(fn (array $plan) => $plan['profile']?->is($profile)));
        $steps = [
            'connect' => ! $state['error'],
            'filters' => ! $state['error'] && $state['filters'] !== [] && collect($state['filters'])->every(fn (array $filter) => $linked->contains('provider_reference', $filter['id'])),
            'probes' => $planner->ready($plans) && $plans->every(fn (array $plan) => $plan['profile'] !== null),
            'check' => $probeProfiles->isNotEmpty() && $probeProfiles->every(fn (NetworkProfile $profile) => $profile->enabled && ($diagnostics->cached($profile)['state'] ?? null) === 'ok'),
        ];

        return view('admin.policy.setup', [
            'steps' => $steps,
            'current' => array_search(false, $steps, true) ?: 'done',
            'composeLine' => $planner->composeFileLine($proxy),
            'behindProxy' => $proxy,
            'ready' => $planner->ready($plans),
            'probeProfiles' => $probeProfiles,
            'state' => $state,
            'filters' => collect($state['filters'])->map(fn (array $filter) => $filter + [
                'linked' => $policies->firstWhere('provider_reference', $filter['id']),
                'suggestion' => $suggestions[$filter['id']] ?? ['action' => 'create', 'policy_id' => null, 'audience' => 'other', 'import' => true, 'parent' => null],
                'parent_name' => $filterNames[$suggestions[$filter['id']]['parent'] ?? ''] ?? null,
                'networks' => $networks->whereIn('id', $filter['network_ids'])->values(),
                'shared' => $networks->whereIn('id', $filter['network_ids'])->filter(fn ($network) => in_array($network['status'], ['multiple_filters', 'unmapped'], true) && count($network['attached']) > 1)->values(),
            ]),
            'policies' => $policies->reject(fn (FilterPolicy $policy) => $policy->isLayer())->values(),
            'parentFilters' => collect($state['filters'])->reject(fn (array $filter) => ($suggestions[$filter['id']]['action'] ?? null) === 'layer')->values(),
            'linkedCount' => $linked->count(),
            'linked' => $linked->values(),
            'mapped' => $networks->whereNotNull('policy')->count(),
            'networkCount' => $networks->count(),
            'parent' => $parent,
            'saved' => $saved,
            'extra' => $extra,
            'extraOptions' => $networks->reject(fn (array $network) => $plans->contains(fn (array $plan) => $plan['network']->provider_network_id === $network['id'] && $plan['policy']))->values(),
            'plans' => $plans,
        ]);
    }

    public function store(Request $request, ControllerSync $sync, ControllerImporter $importer): RedirectResponse
    {
        $validated = $request->validate([
            'filters' => ['required', 'array'],
            'filters.*.as' => ['nullable', 'string', 'max:200', 'regex:/^(skip|type:[a-z0-9_]+|layer:.+)$/'],
            'filters.*.action' => ['nullable', 'required_without:filters.*.as', 'in:skip,link,create,layer'],
            'filters.*.policy_id' => ['nullable', 'integer', 'exists:filter_policies,id'],
            'filters.*.audience' => ['nullable', Rule::exists('network_types', 'key')],
            'filters.*.import' => ['nullable', 'boolean'],
            'filters.*.parent' => ['nullable', 'string', 'max:120'],
        ]);

        $state = $sync->state(true);
        if ($state['error']) {
            return back()->with('error', $state['error']);
        }

        $choices = $this->choices($validated['filters'], $importer->suggest($state));
        $done = $importer->apply($state, $choices, $request->user());

        return redirect()->route('admin.policy.setup.show')->withFragment('probes')
            ->with('success', $done ? 'Set up: '.implode('; ', $done).'. Next: step 3, the probes.' : 'Nothing was changed.');
    }

    /**
     * Turn the form's one plain choice per filter ("Students filtering",
     * "extra blocklist on top of X", "leave it alone") into importer
     * actions. A filter given a network type reuses the policy picked under
     * Advanced, else the suggested spare policy of that type, else gets a new one.
     *
     * @param  array<string, array<string, mixed>>  $filters
     * @param  array<string, array<string, mixed>>  $suggestions
     * @return array<string, array<string, mixed>>
     */
    private function choices(array $filters, array $suggestions): array
    {
        $choices = [];
        foreach ($filters as $id => $choice) {
            $as = (string) ($choice['as'] ?? '');
            if ($as === '') {
                $choices[$id] = $choice;

                continue;
            }
            $import = (bool) ($choice['import'] ?? true);
            if ($as === 'skip') {
                $choices[$id] = ['action' => 'skip'];
            } elseif (str_starts_with($as, 'layer:')) {
                $choices[$id] = ['action' => 'layer', 'parent' => substr($as, 6), 'import' => $import];
            } else {
                $audience = substr($as, 5);
                $picked = filled($choice['policy_id'] ?? null) ? FilterPolicy::query()->main()->find($choice['policy_id']) : null;
                $suggested = $suggestions[$id] ?? null;
                $spare = ($suggested['action'] ?? null) === 'link' && ($suggested['audience'] ?? null) === $audience ? $suggested['policy_id'] : null;
                $policyId = $picked?->id ?? $spare;
                $choices[$id] = $policyId
                    ? ['action' => 'link', 'policy_id' => $policyId, 'audience' => $audience, 'import' => $import]
                    : ['action' => 'create', 'audience' => $audience, 'import' => $import];
            }
        }

        return $choices;
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
        $profiles = $this->prepareProfiles($planner, $this->parentOverride($request), null);
        $audit->log('policy.setup_imported_all', null, ['filters' => count($done), 'profiles' => $profiles]);

        return redirect()->route('admin.policy.setup.show')->withFragment('ready')
            ->with('success', ($done ? 'Brought in: '.implode('; ', $done).'.' : 'Every filter was already set up.')
                .($profiles ? ' Prepared probe profiles: '.implode(', ', $profiles).'.' : '').' Follow the probe steps below, then enable each profile.');
    }

    /** Create disabled network profiles for the planned probes and link them to their policies. */
    public function profiles(Request $request, ProbePlanner $planner, AuditLogger $audit): RedirectResponse
    {
        $created = $this->prepareProfiles($planner, $this->parentOverride($request), $this->extraNetworks($request));
        $audit->log('policy.probe_profiles_prepared', null, ['created' => $created]);

        return back()->with('success', ($created ? 'Created disabled probe profiles: '.implode(', ', $created).'. ' : 'Probe profiles are linked. ')
            .'Enable each one under Settings → Network profiles once its container is running.');
    }

    /** The planned compose.vlan.yml for every probe, as a file. */
    public function compose(Request $request, ProbePlanner $planner): Response
    {
        return response($planner->composeFile($planner->plan($this->parentOverride($request), $this->extraNetworks($request)), $this->behindProxy($request)), 200, [
            'Content-Type' => 'text/yaml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="compose.vlan.yml"',
        ]);
    }

    /**
     * Create (disabled) and link a network profile per planned probe.
     *
     * @param  list<string>|null  $extra  null uses the saved extra networks
     * @return list<string> names of profiles created
     */
    private function prepareProfiles(ProbePlanner $planner, ?string $parent, ?array $extra): array
    {
        $created = [];
        foreach ($planner->plan($parent, $extra) as $plan) {
            $profile = $plan['profile'] ?? NetworkProfile::query()->firstOrCreate(['key' => $plan['key']], [
                'name' => $plan['label'],
                'audience' => $plan['audience'],
                'probe_url' => $plan['probe_url'],
                'enabled' => false,
                'sort_order' => (int) NetworkProfile::query()->max('sort_order') + 1,
            ]);
            $profile->update(['unifi_network_id' => $plan['network']->provider_network_id, 'unifi_policy_id' => $plan['policy']?->provider_reference]);
            if ($plan['policy'] && ! ($plan['pinned'] ?? false) && $plan['policy']->network_profile_id !== $profile->id) {
                $plan['policy']->update(['network_profile_id' => $profile->id]);
            }
            if ($profile->wasRecentlyCreated) {
                $created[] = $profile->name;
            }
        }

        return $created;
    }

    /** Save the probe interfaces and extra networks, so the download and probes:compose match the page. */
    public function saveProbes(Request $request, ProbePlanner $planner, AuditLogger $audit): RedirectResponse
    {
        $interface = ['nullable', 'string', 'regex:'.ProbePlanner::INTERFACE_PATTERN];
        $validated = $request->validate([
            'trunk' => ['required', 'string', 'regex:'.ProbePlanner::INTERFACE_PATTERN],
            'interfaces' => ['nullable', 'array', 'max:200'],
            'interfaces.*' => $interface,
            'extra' => ['nullable', 'array', 'max:50'],
            'extra.*' => ['string', 'regex:/^[A-Za-z0-9_-]{1,80}$/'],
            'addresses' => ['nullable', 'array', 'max:200'],
            'addresses.*' => ['nullable', 'ipv4'],
        ], ['ipv4' => 'Enter an IPv4 address such as 10.0.20.50.', 'regex' => 'Use a Linux interface name such as eno1, eno1.20, vlan20 or br0 (up to 15 characters).']);

        $planner->save([
            'trunk' => $validated['trunk'],
            'interfaces' => collect($validated['interfaces'] ?? [])->filter(fn ($value, $key) => is_string($key) && preg_match('/^[A-Za-z0-9_-]{1,80}$/', $key))->all(),
            'extra' => $validated['extra'] ?? [],
            'addresses' => collect($validated['addresses'] ?? [])->filter(fn ($value, $key) => is_string($key) && preg_match('/^[A-Za-z0-9_-]{1,80}$/', $key))->all(),
        ]);
        $audit->log('policy.probe_interfaces_saved', null, ['trunk' => $validated['trunk'], 'interfaces' => count(array_filter($validated['interfaces'] ?? []))]);

        // Profiles follow the plan straight away, so step 4 can list them.
        $this->prepareProfiles($planner, null, null);

        return redirect()->route('admin.policy.setup.show')->withFragment('host')->with('success', 'Saved. Now run the steps on the Docker host below. If you changed an address, remove that probe\'s Docker network first (docker network rm) and create it again.');
    }

    /** Reached through a trusted reverse proxy: it sent X-Forwarded-For (a trusted address alone is not enough). */
    private function behindProxy(Request $request): bool
    {
        return $request->isFromTrustedProxy() && $request->headers->has('X-Forwarded-For');
    }

    private function parentOverride(Request $request): ?string
    {
        $parent = $request->input('parent');

        return is_string($parent) && preg_match(ProbePlanner::INTERFACE_PATTERN, $parent) ? $parent : null;
    }

    /** @return list<string>|null network ids picked for extra probes; null when not given */
    private function extraNetworks(Request $request): ?array
    {
        if (! $request->has('extra')) {
            return null;
        }

        return collect((array) $request->input('extra', []))->filter(fn ($id) => is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,80}$/', $id))->values()->all();
    }
}
