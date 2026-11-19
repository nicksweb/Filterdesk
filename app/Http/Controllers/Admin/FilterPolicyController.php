<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Audience;
use App\Http\Controllers\Controller;
use App\Models\FilterPolicy;
use App\Models\NetworkProfile;
use App\Models\PolicyList;
use App\Services\AuditLogger;
use App\Services\Policy\PolicyEngine;
use App\Services\Policy\PolicyListParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Filter policies mirror the content-filter profiles in the network
 * controller. Each has its own reconciliation: paste the controller's
 * current lists, review the change set, copy the desired lists back.
 */
class FilterPolicyController extends Controller
{
    public function show(FilterPolicy $policy, PolicyEngine $engine): View
    {
        return view('admin.policy.policy', [
            'policy' => $policy->load('networkProfile', 'snapshotBy'),
            'report' => $engine->reconcile($policy),
            'lists' => PolicyList::query()->appliesTo($policy)->ordered()->withCount('entries')->get(),
            'profiles' => NetworkProfile::query()->orderBy('sort_order')->get(),
            'rules' => $engine->rules(),
        ]);
    }

    public function store(Request $request, PolicyEngine $engine, AuditLogger $audit): RedirectResponse
    {
        $validated = $this->validated($request);
        $policy = FilterPolicy::query()->create($validated + ['slug' => $engine->uniqueSlug($validated['name'])]);
        $engine->ensureAudienceLists($policy->audience);
        $audit->log('policy.created', $policy, ['audience' => $policy->audience->value]);

        return redirect()->route('admin.policy.policies.show', $policy)->with('success', "{$policy->name} created. Audience lists for {$policy->audience->label()} are ready.");
    }

    public function update(Request $request, FilterPolicy $policy, PolicyEngine $engine, AuditLogger $audit): RedirectResponse
    {
        $policy->update($this->validated($request));
        $engine->ensureAudienceLists($policy->audience);
        $audit->log('policy.updated', $policy);

        return back()->with('success', 'Policy updated.');
    }

    public function destroy(FilterPolicy $policy, AuditLogger $audit): RedirectResponse
    {
        $audit->log('policy.deleted', $policy, ['name' => $policy->name]);
        $policy->delete();

        return redirect()->route('admin.policy.index')->with('success', 'Policy deleted. Its own lists were removed; site-wide and audience lists are unchanged.');
    }

    public function snapshot(Request $request, FilterPolicy $policy, PolicyListParser $parser, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'current_allowlist' => ['nullable', 'string', 'max:400000'],
            'current_blocklist' => ['nullable', 'string', 'max:400000'],
        ]);
        $allow = $parser->parseDetailed($validated['current_allowlist'] ?? '', 'current_allowlist');
        $block = $parser->parseDetailed($validated['current_blocklist'] ?? '', 'current_blocklist');

        $policy->update([
            'current_allowlist' => $allow['entries'],
            'current_blocklist' => $block['entries'],
            'snapshot_at' => now(),
            'snapshot_by_id' => $request->user()->id,
        ]);
        $audit->log('policy.snapshot_saved', $policy, ['allow_count' => count($allow['entries']), 'block_count' => count($block['entries'])]);

        $notes = array_unique([...$allow['notes'], ...$block['notes']]);

        return back()->with('success', 'Controller snapshot saved and compared.'.($notes ? ' '.implode(' ', $notes) : ''));
    }

    /** Mark the controller as matching the desired state after IT pastes it in. */
    public function markSynced(Request $request, FilterPolicy $policy, PolicyEngine $engine, AuditLogger $audit): RedirectResponse
    {
        $desired = $engine->desired($policy);
        $policy->update([
            'current_allowlist' => $desired['allow'],
            'current_blocklist' => $desired['block'],
            'snapshot_at' => now(),
            'snapshot_by_id' => $request->user()->id,
        ]);
        $audit->log('policy.marked_synced', $policy, ['allow_count' => count($desired['allow']), 'block_count' => count($desired['block'])]);

        return back()->with('success', "{$policy->name} recorded as matching the desired lists.");
    }

    public function export(FilterPolicy $policy, string $kind, PolicyEngine $engine): Response
    {
        abort_unless(in_array($kind, ['allow', 'block'], true), 404);
        $domains = $engine->desired($policy)[$kind];

        return response(implode("\n", $domains)."\n", 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$policy->slug.'-'.$kind.'list.txt"',
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'audience' => ['required', Rule::enum(Audience::class)],
            'description' => ['nullable', 'string', 'max:1000'],
            'network_profile_id' => ['nullable', 'exists:network_profiles,id'],
            'provider_reference' => ['nullable', 'string', 'max:190'],
            'enabled' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        return [
            'name' => $validated['name'],
            'audience' => Audience::from($validated['audience']),
            'description' => $validated['description'] ?? null,
            'network_profile_id' => $validated['network_profile_id'] ?? null,
            'provider_reference' => $validated['provider_reference'] ?? null,
            'enabled' => $request->boolean('enabled', true),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ];
    }
}
