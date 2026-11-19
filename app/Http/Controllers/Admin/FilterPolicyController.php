<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Audience;
use App\Enums\EntrySource;
use App\Enums\ListKind;
use App\Http\Controllers\Controller;
use App\Models\ControllerPush;
use App\Models\FilterPolicy;
use App\Models\NetworkProfile;
use App\Models\PolicyList;
use App\Services\AuditLogger;
use App\Services\Network\ProviderRequestFailed;
use App\Services\Policy\ControllerPusher;
use App\Services\Policy\ControllerSync;
use App\Services\Policy\PolicyEngine;
use App\Services\Policy\PolicyListParser;
use App\Services\Policy\SyncChecker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * Filter policies mirror the content-filter profiles in the network
 * controller. Each has its own reconciliation: paste the controller's
 * current lists, review the change set, copy the desired lists back.
 */
class FilterPolicyController extends Controller
{
    public function show(FilterPolicy $policy, PolicyEngine $engine, ControllerSync $sync, ControllerPusher $pusher): View
    {
        $lists = PolicyList::query()->appliesTo($policy)->ordered()->withCount('entries')->get();

        return view('admin.policy.policy', [
            'policy' => $policy->load('networkProfile', 'snapshotBy', 'networkAssignments'),
            'report' => $engine->reconcile($policy),
            'lists' => $lists,
            'adoptTargets' => ['allow' => $lists->where('kind', ListKind::Allow)->values(), 'block' => $lists->where('kind', ListKind::Block)->values()],
            'canPull' => $sync->available() && filled($policy->provider_reference),
            'canPush' => $pusher->enabled() && filled($policy->provider_reference),
            'autoPush' => $pusher->automatic(),
            'categories' => $pusher->enabled() && filled($policy->provider_reference) ? $this->categoryOptions($policy, $sync) : null,
            'pushes' => ControllerPush::query()->where('filter_policy_id', $policy->id)->with('user:id,name')->latest('id')->limit(10)->get(),
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

    /** Replace the snapshot with the linked controller filter's live lists. */
    public function pull(Request $request, FilterPolicy $policy, ControllerSync $sync, SyncChecker $checker, AuditLogger $audit): RedirectResponse
    {
        $result = $sync->pull($policy, $request->user());
        if ($result['ok']) {
            $audit->log('policy.snapshot_pulled', $policy, ['filter' => $policy->provider_name]);
            $checker->checkOne($policy->refresh(), refresh: false);
        }

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /**
     * Accept the controller's current lists as intended, so edits made
     * there stop being reported as drift. Entries only in the controller
     * stay unmanaged unless imported.
     */
    public function acceptController(Request $request, FilterPolicy $policy, ControllerSync $sync, SyncChecker $checker, AuditLogger $audit): RedirectResponse
    {
        $result = $sync->pull($policy, $request->user());
        if (! $result['ok']) {
            return back()->with('error', $result['message']);
        }

        $policy->refresh();
        $checker->setBaseline($policy, $policy->current_allowlist ?? [], $policy->current_blocklist ?? [], $sync->filterFor($policy)['categories'] ?? []);
        $checker->checkOne($policy, refresh: false);
        $audit->log('policy.controller_state_accepted', $policy, ['details' => $policy->sync_details['summary'] ?? null]);

        return back()->with('success', "{$policy->provider_name}'s current lists are accepted. Later edits in the controller will be reported from now on.");
    }

    /**
     * Stop keeping hostnames out of this policy after they were removed in
     * the controller, so the next push puts them back.
     */
    public function restore(Request $request, FilterPolicy $policy, ControllerPusher $pusher, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::enum(ListKind::class)],
            'domains' => ['required', 'array', 'min:1'],
            'domains.*' => ['string', 'max:253'],
        ]);
        $exclusions = $policy->exclusions();
        $kind = $validated['kind'];
        $restored = array_values(array_intersect($exclusions[$kind], $validated['domains']));
        $exclusions[$kind] = array_values(array_diff($exclusions[$kind], $restored));
        $policy->update(['controller_exclusions' => $exclusions]);
        $audit->log('policy.controller_exclusions_restored', $policy, ['kind' => $kind, 'domains' => $restored]);

        $message = count($restored).' hostname(s) restored to '.$policy->name.'.';
        if ($pusher->automatic()) {
            $message .= ' '.$pusher->push($policy, $request->user(), 'restore')['message'];
        } else {
            $message .= ' Push to put them back in the controller.';
        }

        return back()->with('success', $message);
    }

    /** Change which content categories the linked controller filter blocks. */
    public function categories(Request $request, FilterPolicy $policy, ControllerPusher $pusher, ControllerSync $sync, SyncChecker $checker, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate(['categories' => ['nullable', 'array', 'max:300'], 'categories.*' => ['string', 'max:80', 'regex:/^[A-Z0-9_]+$/']]);
        $writer = $pusher->writer();
        if (! $writer || ! $pusher->enabled() || blank($policy->provider_reference)) {
            return back()->with('error', 'Link this policy to a controller filter and set the network provider to Active first.');
        }

        $sync->forget();
        $filter = $sync->filterFor($policy, $sync->state(true));
        if (! $filter) {
            return back()->with('error', 'The linked controller filter could not be read.');
        }

        $available = $sync->reader()?->availableCategories() ?? [];
        $categories = collect($validated['categories'] ?? [])->filter(fn ($category) => in_array($category, $available, true) || in_array($category, $filter['categories'], true))->unique()->sort()->values()->all();
        $added = array_values(array_diff($categories, $filter['categories']));
        $removed = array_values(array_diff($filter['categories'], $categories));
        if ($added === [] && $removed === []) {
            return back()->with('success', 'No category changes.');
        }

        try {
            $writer->writeContentFilterCategories($filter['id'], $categories);
        } catch (ProviderRequestFailed $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $checker->setCategoryBaseline($policy, $categories);
        $sync->forget();
        $checker->checkOne($policy->refresh());
        $audit->log('policy.controller_categories_changed', $policy, ['filter' => $filter['name'], 'added' => $added, 'removed' => $removed, 'before' => $filter['categories']]);

        return back()->with('success', "{$filter['name']} now blocks ".count($categories).' categories ('.($added ? '+'.implode(', +', $added) : '').($added && $removed ? '; ' : '').($removed ? '−'.implode(', −', $removed) : '').').');
    }

    /** Apply this policy's change set to its linked controller filter. */
    public function push(Request $request, FilterPolicy $policy, ControllerPusher $pusher): RedirectResponse
    {
        $result = $pusher->push($policy, $request->user());

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /** Save the linked filter back unchanged to prove the key may write it. */
    public function verifyWrite(FilterPolicy $policy, ControllerPusher $pusher, AuditLogger $audit): RedirectResponse
    {
        $writer = $pusher->writer();
        if (! $writer || blank($policy->provider_reference)) {
            return back()->with('error', 'Link this policy to a controller filter and set the network provider to Active first.');
        }

        $result = $writer->verifyContentFilterWrite($policy->provider_reference);
        $audit->log('policy.controller_write_verified', $policy, ['ok' => $result['ok']]);

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function rollback(Request $request, ControllerPush $push, ControllerPusher $pusher): RedirectResponse
    {
        $result = $pusher->rollback($push, $request->user());

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /**
     * Bring entries that exist only in the controller (added there by hand)
     * into a managed list, so they are kept on the next reconciliation.
     */
    public function adopt(Request $request, FilterPolicy $policy, PolicyListParser $parser, PolicyEngine $engine, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::enum(ListKind::class)],
            'domains' => ['required', 'array', 'min:1', 'max:2000'],
            'domains.*' => ['string', 'max:253'],
            'policy_list_id' => ['required', 'integer'],
        ]);
        $kind = ListKind::from($validated['kind']);
        $list = PolicyList::query()->appliesTo($policy)->where('kind', $kind)->find($validated['policy_list_id']);
        if (! $list) {
            throw ValidationException::withMessages(['policy_list_id' => "Choose a {$kind->value} list that applies to {$policy->name}."]);
        }

        $current = $kind === ListKind::Allow ? ($policy->current_allowlist ?? []) : ($policy->current_blocklist ?? []);
        $domains = array_values(array_intersect(array_unique(array_map($parser->normalise(...), $validated['domains'])), $current));
        if ($domains === []) {
            throw ValidationException::withMessages(['domains' => 'Select hostnames from the controller snapshot.']);
        }

        $source = $policy->provider_name ?: $policy->name;
        foreach ($domains as $domain) {
            $engine->upsertEntry($list, $domain, ['source' => EntrySource::Import, 'added_by_id' => $request->user()->id, 'note' => "Imported from controller filter {$source}"]);
        }
        $audit->log('policy.controller_entries_adopted', $list, ['policy' => $policy->slug, 'domains' => $domains]);

        return back()->with('success', count($domains)." hostname(s) imported into {$list->name}. They are now managed here and kept on the controller.");
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
        app(SyncChecker::class)->setBaseline($policy, $desired['allow'], $desired['block']);
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

    /** @return array{available: list<string>, blocked: list<string>}|null */
    private function categoryOptions(FilterPolicy $policy, ControllerSync $sync): ?array
    {
        $filter = $sync->filterFor($policy);
        if (! $filter) {
            return null;
        }

        try {
            $available = Cache::remember('controller.categories.v1', 3600, fn () => $sync->reader()?->availableCategories() ?? []);
        } catch (Throwable) {
            return null;
        }

        return ['available' => collect([...$available, ...$filter['categories']])->unique()->sort()->values()->all(), 'blocked' => $filter['categories']];
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
