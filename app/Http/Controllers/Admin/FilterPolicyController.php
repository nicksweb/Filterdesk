<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EntrySource;
use App\Enums\ListKind;
use App\Http\Controllers\Controller;
use App\Jobs\RunManualPush;
use App\Models\ControllerPush;
use App\Models\FilterPolicy;
use App\Models\NetworkProfile;
use App\Models\PolicyEntry;
use App\Models\PolicyList;
use App\Services\AuditLogger;
use App\Services\Network\ProviderRequestFailed;
use App\Services\Network\WritesContentFilters;
use App\Services\Policy\ControllerPusher;
use App\Services\Policy\ControllerSync;
use App\Services\Policy\PolicyEngine;
use App\Services\Policy\PolicyListParser;
use App\Services\Policy\PolicyMerger;
use App\Services\Policy\RunTogetherEntries;
use App\Services\Policy\SyncChecker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
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
            'policy' => $policy->load('networkProfile', 'snapshotBy', 'networkAssignments', 'layer', 'hoursLayer', 'layerOf'),
            'slowListSize' => ControllerPusher::SLOW_LIST_ENTRIES,
            'report' => $engine->reconcile($policy),
            'runTogether' => app(RunTogetherEntries::class)->forPolicy($policy),
            'lists' => $lists,
            'adoptTargets' => ['allow' => $lists->where('kind', ListKind::Allow)->values(), 'block' => $lists->where('kind', ListKind::Block)->values()],
            'canPull' => $sync->available() && filled($policy->provider_reference),
            'canPush' => $pusher->enabled() && filled($policy->provider_reference),
            'autoPush' => $pusher->automatic(),
            'categories' => $pusher->enabled() && filled($policy->provider_reference) && ! $policy->isLayer() ? $this->categoryOptions($policy, $sync) : null,
            'pushStatus' => Cache::get(RunManualPush::statusKey('policy', $policy->id)),
            // By policy and by filter: a push made while another policy was linked to this filter can still be rolled back here.
            'pushes' => ControllerPush::query()->where(fn ($query) => $query->where('filter_policy_id', $policy->id)
                ->when(filled($policy->provider_reference), fn ($query) => $query->orWhere('provider_reference', $policy->provider_reference)))
                ->with('user:id,name', 'filterPolicy:id,name')->latest('id')->limit(15)->get(),
            'profiles' => NetworkProfile::query()->orderBy('sort_order')->get(),
            'rules' => $engine->rules(),
        ]);
    }

    public function store(Request $request, PolicyEngine $engine, AuditLogger $audit): RedirectResponse
    {
        $validated = $this->validated($request);
        $policy = FilterPolicy::query()->create($validated + ['slug' => $engine->uniqueSlug($validated['name'])]);
        $engine->ensureAudienceLists($policy->audience);
        $audit->log('policy.created', $policy, ['network_type' => $policy->audience]);

        return redirect()->route('admin.policy.policies.show', $policy)->with('success', "{$policy->name} created. Shared lists for {$policy->typeLabel()} are ready.");
    }

    public function update(Request $request, FilterPolicy $policy, PolicyEngine $engine, AuditLogger $audit): RedirectResponse
    {
        $policy->update($this->validated($request));
        $engine->ensureAudienceLists($policy->audience);
        $audit->log('policy.updated', $policy);

        return back()->with('success', 'Policy updated.');
    }

    /** Merge this (duplicate) policy into another, then delete it. */
    public function merge(Request $request, FilterPolicy $policy, PolicyMerger $merger): RedirectResponse
    {
        $validated = $request->validate(['into_id' => ['required', 'integer', 'exists:filter_policies,id']]);
        $into = FilterPolicy::query()->findOrFail($validated['into_id']);
        try {
            $result = $merger->merge($policy, $into, $request->user());
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('admin.policy.policies.show', $into)->with('success', "{$policy->name} was merged into {$into->name}: "
            .number_format($result['entries']).' entries and '.$result['lists'].' list(s) moved, '.$result['networks'].' network(s) remapped. Nothing changed in UniFi.');
    }

    /** Split an entry that looks like two hostnames run together. */
    public function splitEntry(Request $request, FilterPolicy $policy, PolicyEntry $entry, RunTogetherEntries $entries): RedirectResponse
    {
        try {
            [$first, $second] = $entries->splitEntry($entry, $request->user());
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', "Split into {$first} and {$second}. The old entry is taken out of UniFi at the next publish.");
    }

    /** Keep an entry that only looks run together. */
    public function keepEntry(Request $request, FilterPolicy $policy, RunTogetherEntries $entries): RedirectResponse
    {
        $validated = $request->validate(['domain' => ['required', 'string', 'max:253']]);
        $entries->keep($validated['domain'], $request->user());

        return back()->with('success', "{$validated['domain']} is kept as it is.");
    }

    public function destroy(FilterPolicy $policy, AuditLogger $audit): RedirectResponse
    {
        $audit->log('policy.deleted', $policy, ['name' => $policy->name]);
        $policy->delete();

        return redirect()->route('admin.policy.index')->with('success', 'Policy deleted. Its own lists were removed; site-wide and network type lists are unchanged.');
    }

    /** Keep a policy but take its link to a UniFi filter away (for one whose filter was deleted in UniFi). */
    public function unlink(FilterPolicy $policy, AuditLogger $audit): RedirectResponse
    {
        $audit->log('policy.filter_unlinked', $policy, ['filter' => $policy->provider_name, 'reference' => $policy->provider_reference]);
        $policy->forceFill([
            'provider_reference' => null, 'provider_name' => null, 'sync_status' => null, 'sync_details' => null, 'sync_baseline' => null,
            'current_allowlist' => null, 'current_blocklist' => null, 'snapshot_at' => null, 'controller_managed' => null, 'push_hold' => null,
        ])->save();

        return back()->with('success', "{$policy->name} is kept, not linked to a UniFi filter. Its lists stay; link it to a filter on Networks & filters to push them again.");
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
            $message .= ' '.$pusher->push($policy, $request->user(), 'restore', reviewed: true)['message'];
        } else {
            $message .= ' Push to put them back in the controller.';
        }

        return back()->with('success', $message);
    }

    /**
     * Change which content categories the linked controller filter blocks:
     * the ticked ones, or a copy of another filter's ("copy_from"), and
     * optionally the same set on other filters too ("also_to"), so Students
     * and Guests can share one selection.
     */
    public function categories(Request $request, FilterPolicy $policy, ControllerPusher $pusher, ControllerSync $sync, SyncChecker $checker, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'categories' => ['nullable', 'array', 'max:300'], 'categories.*' => ['string', 'max:80', 'regex:/^[A-Z0-9_]+$/'],
            'copy_from' => ['nullable', 'integer', Rule::exists('filter_policies', 'id')->whereNotNull('provider_reference')],
            'also_to' => ['nullable', 'array', 'max:50'], 'also_to.*' => ['integer', Rule::exists('filter_policies', 'id')->whereNotNull('provider_reference')],
        ]);
        $writer = $pusher->writer();
        if (! $writer || ! $pusher->enabled() || blank($policy->provider_reference)) {
            return back()->with('error', 'Link this policy to a controller filter and set the network provider to Active first.');
        }

        $sync->forget();
        $state = $sync->state(true);
        $filter = $sync->filterFor($policy, $state);
        if (! $filter) {
            return back()->with('error', 'The linked controller filter could not be read.');
        }

        $requested = $validated['categories'] ?? [];
        $copiedFrom = null;
        if (filled($validated['copy_from'] ?? null)) {
            $copiedFrom = FilterPolicy::query()->findOrFail($validated['copy_from']);
            $source = $sync->filterFor($copiedFrom, $state);
            if (! $source || $copiedFrom->is($policy)) {
                return back()->with('error', 'That filter could not be read from the controller.');
            }
            $requested = $source['categories'];
        }

        $available = $sync->reader()?->availableCategories() ?? [];
        $targets = collect([$policy])->merge(FilterPolicy::query()->whereIn('id', $validated['also_to'] ?? [])->whereKeyNot($policy->id)->get()
            ->reject(fn (FilterPolicy $other) => $other->isLayer()));
        $messages = [];
        $errors = [];
        foreach ($targets as $target) {
            $targetFilter = $target->is($policy) ? $filter : $sync->filterFor($target, $state);
            if (! $targetFilter) {
                $errors[] = "{$target->name}: its controller filter could not be read.";

                continue;
            }
            $categories = collect($requested)->filter(fn ($category) => in_array($category, $available, true) || in_array($category, $targetFilter['categories'], true))->unique()->sort()->values()->all();
            $result = $this->saveCategories($target, $targetFilter, $categories, $writer, $sync, $checker, $audit, $copiedFrom);
            if (is_string($result)) {
                $errors[] = "{$targetFilter['name']}: {$result}";
            } elseif ($result === []) {
                $messages[] = "{$targetFilter['name']}: no category changes.";
            } else {
                $messages[] = "{$targetFilter['name']} now blocks ".count($categories).' categories ('.($result['added'] ? '+'.implode(', +', $result['added']) : '').($result['added'] && $result['removed'] ? '; ' : '').($result['removed'] ? '−'.implode(', −', $result['removed']) : '').').';
            }
        }

        $response = back();
        if ($errors) {
            $response = $response->with('error', implode(' ', $errors));
        }

        return $messages ? $response->with('success', ($copiedFrom ? 'Copied from '.($copiedFrom->provider_name ?: $copiedFrom->name).'. ' : '').implode(' ', $messages)) : $response;
    }

    /**
     * Write one filter's categories (and its layer's), then check it.
     *
     * @param  array<string, mixed>  $filter
     * @param  list<string>  $categories
     * @return array{added: list<string>, removed: list<string>}|array{}|string the change, nothing to change, or the error
     */
    private function saveCategories(FilterPolicy $policy, array $filter, array $categories, WritesContentFilters $writer, ControllerSync $sync, SyncChecker $checker, AuditLogger $audit, ?FilterPolicy $copiedFrom): array|string
    {
        $added = array_values(array_diff($categories, $filter['categories']));
        $removed = array_values(array_diff($filter['categories'], $categories));
        if ($added === [] && $removed === []) {
            return [];
        }

        try {
            $writer->writeContentFilterCategories($filter['id'], $categories);
        } catch (ProviderRequestFailed $exception) {
            return $exception->getMessage();
        }

        $checker->setCategoryBaseline($policy, $categories);
        // A layer mirrors its parent's categories so it never blocks a category the parent allows.
        $layer = $policy->activeLayer();
        if ($layer && $categories !== []) {
            try {
                $writer->writeContentFilterCategories($layer->provider_reference, $categories);
                $checker->setCategoryBaseline($layer, $categories);
            } catch (ProviderRequestFailed $exception) {
                session()->flash('error', "{$layer->name} kept its old categories: {$exception->getMessage()}");
            }
        }
        $sync->forget();
        $checker->checkOne($policy->refresh());
        if ($layer) {
            $checker->checkOne($layer->refresh(), refresh: false);
        }
        if ($added !== []) {
            // Newly blocked categories may cover sites approved without an allow entry.
            Artisan::call('categories:guard');
        }
        $audit->log('policy.controller_categories_changed', $policy, ['filter' => $filter['name'], 'added' => $added, 'removed' => $removed, 'before' => $filter['categories']]
            + ($copiedFrom ? ['copied_from' => $copiedFrom->provider_name ?: $copiedFrom->name] : []));

        return ['added' => $added, 'removed' => $removed];
    }

    /** Apply this policy's change set to its linked controller filter. */
    public function push(Request $request, FilterPolicy $policy, ControllerPusher $pusher): RedirectResponse
    {
        if (! $pusher->enabled()) {
            return back()->with('error', 'Pushing is off. Set the network provider to Active under Settings → Network provider.');
        }
        if (blank($policy->provider_reference)) {
            return back()->with('error', 'Link this policy to a controller filter first.');
        }
        $result = RunManualPush::start('policy', $policy->id, $request->user());

        return RunManualPush::flash(back(), $result, "Pushing {$policy->name}. It runs in the background; the result appears in the push history in a minute or so.");
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

    /** @return array{available: list<string>, blocked: list<string>, others: list<array{id: int, name: string, count: int, differs: int}>}|null */
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

        // Other linked filters, to copy a selection from or save the same one to (Students and Guests often match).
        $state = $sync->state();
        $others = FilterPolicy::query()->whereNotNull('provider_reference')->whereKeyNot($policy->id)->whereNull('layer_of_id')->ordered()->get()
            ->map(function (FilterPolicy $other) use ($sync, $state, $filter): ?array {
                $otherFilter = $sync->filterFor($other, $state);

                return $otherFilter ? [
                    'id' => $other->id, 'name' => $otherFilter['name'], 'count' => count($otherFilter['categories']),
                    'differs' => count(array_diff($otherFilter['categories'], $filter['categories'])) + count(array_diff($filter['categories'], $otherFilter['categories'])),
                ] : null;
            })->filter()->values()->all();

        return ['available' => collect([...$available, ...$filter['categories']])->unique()->sort()->values()->all(), 'blocked' => $filter['categories'], 'others' => $others];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'audience' => ['required', Rule::exists('network_types', 'key')],
            'description' => ['nullable', 'string', 'max:1000'],
            'network_profile_id' => ['nullable', 'exists:network_profiles,id'],
            'provider_reference' => ['nullable', 'string', 'max:190'],
            'enabled' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        return [
            'name' => $validated['name'],
            'audience' => $validated['audience'],
            'description' => $validated['description'] ?? null,
            'network_profile_id' => $validated['network_profile_id'] ?? null,
            'provider_reference' => $validated['provider_reference'] ?? null,
            'enabled' => $request->boolean('enabled', true),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ];
    }
}
