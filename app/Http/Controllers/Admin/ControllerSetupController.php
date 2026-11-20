<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Audience;
use App\Enums\EntrySource;
use App\Enums\ListKind;
use App\Http\Controllers\Controller;
use App\Models\FilterPolicy;
use App\Models\NetworkProfile;
use App\Services\AuditLogger;
use App\Services\Policy\ControllerSync;
use App\Services\Policy\PolicyEngine;
use App\Services\Policy\PolicyListParser;
use App\Services\Policy\ProbePlanner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Guided setup from a connected controller: bring every content filter in
 * as a filter policy with its own lists, map the networks it applies to, and
 * recommend a probe container per policy so checks match real filtering.
 */
class ControllerSetupController extends Controller
{
    public function show(Request $request, ControllerSync $sync, ProbePlanner $planner): View
    {
        $state = $sync->state($request->boolean('refresh'));
        $policies = FilterPolicy::query()->main()->ordered()->get();
        $networks = $state['error'] ? collect() : $sync->networkReport($state);

        return view('admin.policy.setup', [
            'state' => $state,
            'filters' => collect($state['filters'])->map(fn (array $filter) => $filter + [
                'linked' => $policies->firstWhere('provider_reference', $filter['id']),
                'suggested_audience' => $this->suggestAudience($filter['name'], $networks->whereIn('id', $filter['network_ids'])),
                'networks' => $networks->whereIn('id', $filter['network_ids'])->values(),
                'shared' => $networks->whereIn('id', $filter['network_ids'])->filter(fn ($network) => count($network['attached']) > 1)->values(),
            ]),
            'policies' => $policies,
            'parent' => (string) $request->query('parent', 'eth0'),
            'plans' => $planner->plan((string) $request->query('parent', 'eth0')),
        ]);
    }

    public function store(Request $request, ControllerSync $sync, PolicyEngine $engine, PolicyListParser $parser, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'filters' => ['required', 'array'],
            'filters.*.action' => ['required', 'in:skip,link,create'],
            'filters.*.policy_id' => ['nullable', 'integer', 'exists:filter_policies,id'],
            'filters.*.audience' => ['nullable', Rule::enum(Audience::class)],
            'filters.*.import' => ['nullable', 'boolean'],
        ]);

        $state = $sync->state(true);
        if ($state['error']) {
            return back()->with('error', $state['error']);
        }

        $done = [];
        foreach ($validated['filters'] as $filterId => $choice) {
            $filter = collect($state['filters'])->firstWhere('id', (string) $filterId);
            if (! $filter || $choice['action'] === 'skip') {
                continue;
            }

            if ($choice['action'] === 'link' && filled($choice['policy_id'] ?? null)) {
                $policy = FilterPolicy::query()->findOrFail($choice['policy_id']);
            } else {
                $audience = Audience::from($choice['audience'] ?? Audience::Other->value);
                $policy = FilterPolicy::query()->create([
                    'name' => $filter['name'], 'slug' => $engine->uniqueSlug($filter['name']), 'audience' => $audience,
                    'description' => "Content filter {$filter['name']} in the controller.",
                ]);
                $engine->ensureAudienceLists($audience);
            }

            $imported = 0;
            if (! empty($choice['import'])) {
                $imported = $this->importEntries($policy, $filter, $parser, $engine, $request);
            }

            $sync->link($filter, $policy, $request->user(), remapNetworks: true);
            $audit->log('policy.setup_from_controller', $policy, ['filter' => $filter['name'], 'imported' => $imported]);
            $done[] = "{$filter['name']} → {$policy->name}".($imported ? " ({$imported} entries imported)" : '');
        }

        return redirect()->route('admin.policy.setup.show')->withFragment('probes')
            ->with('success', $done ? 'Set up: '.implode('; ', $done).'. Next, set up a probe for each policy below.' : 'Nothing was changed.');
    }

    /** Create disabled network profiles for the planned probes and link them to their policies. */
    public function profiles(Request $request, ProbePlanner $planner, AuditLogger $audit): RedirectResponse
    {
        $created = [];
        foreach ($planner->plan((string) $request->input('parent', 'eth0')) as $plan) {
            $profile = $plan['profile'] ?? NetworkProfile::query()->firstOrCreate(['key' => $plan['key']], [
                'name' => $plan['policy']->name.' network',
                'audience' => $plan['policy']->audience->value,
                'probe_url' => $plan['probe_url'],
                'enabled' => false,
                'sort_order' => (int) NetworkProfile::query()->max('sort_order') + 1,
            ]);
            $profile->update(['unifi_network_id' => $plan['network']->provider_network_id, 'unifi_policy_id' => $plan['policy']->provider_reference]);
            if ($plan['policy']->network_profile_id !== $profile->id) {
                $plan['policy']->update(['network_profile_id' => $profile->id]);
            }
            if ($profile->wasRecentlyCreated) {
                $created[] = $profile->name;
            }
        }
        $audit->log('policy.probe_profiles_prepared', null, ['created' => $created]);

        return back()->with('success', ($created ? 'Created disabled probe profiles: '.implode(', ', $created).'. ' : 'Probe profiles are linked. ')
            .'Enable each one under Settings → Network profiles once its container is running.');
    }

    /**
     * Import a controller filter's current entries into lists owned by
     * this policy only, so filters sharing an audience stay separate.
     *
     * @param  array<string, mixed>  $filter
     */
    private function importEntries(FilterPolicy $policy, array $filter, PolicyListParser $parser, PolicyEngine $engine, Request $request): int
    {
        $count = 0;
        foreach ([[ListKind::Allow, $filter['allow']], [ListKind::Block, $filter['block']]] as [$kind, $entries]) {
            $domains = $parser->parse(implode("\n", $entries));
            if ($domains === []) {
                continue;
            }
            $list = $engine->ownList($policy, $kind, "Imported from the controller filter {$filter['name']}.");
            foreach ($domains as $domain) {
                $engine->upsertEntry($list, $domain, ['source' => EntrySource::Import, 'added_by_id' => $request->user()->id, 'note' => "Imported from {$filter['name']}"]);
                $count++;
            }
        }

        return $count;
    }

    /** Guess an audience from the filter and network names. */
    private function suggestAudience(string $name, $networks): Audience
    {
        $text = Str::lower($name.' '.$networks->pluck('name')->join(' '));

        return match (true) {
            Str::contains($text, ['student', 'pupil', 'learner', 'junior', 'senior', 'boarding', 'byod']) => Audience::Student,
            Str::contains($text, ['guest', 'visitor', 'public', 'untrusted']) => Audience::Guest,
            Str::contains($text, ['staff', 'teacher', 'admin', 'corporate', 'employee', 'faculty']) => Audience::Staff,
            default => Audience::Other,
        };
    }
}
