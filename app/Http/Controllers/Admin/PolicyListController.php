<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Audience;
use App\Enums\EntrySource;
use App\Enums\ListKind;
use App\Enums\ListScope;
use App\Http\Controllers\Controller;
use App\Models\FilterPolicy;
use App\Models\PolicyList;
use App\Services\AuditLogger;
use App\Services\Policy\PolicyEngine;
use App\Services\Policy\PolicyListParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PolicyListController extends Controller
{
    public function show(Request $request, PolicyList $list): View
    {
        $search = trim((string) $request->query('q', ''));

        return view('admin.policy.list', [
            'list' => $list->load('filterPolicy'),
            'entries' => $list->entries()->with(['websiteRequest:id,reference', 'addedBy:id,name'])
                ->when($search !== '', fn ($query) => $query->where('domain', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], strtolower($search)).'%'))
                ->orderBy('domain')->paginate(100)->withQueryString(),
            'policies' => FilterPolicy::query()->ordered()->get()->filter(fn (FilterPolicy $policy) => $list->appliesTo($policy)),
            'allPolicies' => FilterPolicy::query()->ordered()->get(),
            'search' => $search,
        ]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $list = PolicyList::query()->create($this->validated($request));
        $audit->log('policy.list_created', $list, ['scope' => $list->scope->value, 'kind' => $list->kind->value]);

        return redirect()->route('admin.policy.lists.show', $list)->with('success', 'List created.');
    }

    public function update(Request $request, PolicyList $list, AuditLogger $audit): RedirectResponse
    {
        $list->update($this->validated($request));
        $audit->log('policy.list_updated', $list);

        return back()->with('success', 'List updated.');
    }

    public function destroy(PolicyList $list, AuditLogger $audit): RedirectResponse
    {
        $remaining = PolicyList::query()->where('kind', $list->kind)->where('scope', $list->scope)->where('audience', $list->audience)->whereKeyNot($list->id)->exists();
        if ($list->is_default && ! $remaining) {
            return back()->with('error', 'This is the default list that receives approved requests. Mark another list as default first.');
        }

        $audit->log('policy.list_deleted', $list, ['name' => $list->name, 'entries' => $list->entries()->count()]);
        $list->delete();

        return redirect()->route('admin.policy.index')->with('success', 'List deleted.');
    }

    /** Merge or replace manual entries from pasted text. Request-sourced entries are never replaced. */
    public function import(Request $request, PolicyList $list, PolicyListParser $parser, PolicyEngine $engine, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'domains' => ['nullable', 'string', 'max:400000'],
            'mode' => ['required', 'in:merge,replace'],
        ]);
        $parsed = $parser->parseDetailed($validated['domains'] ?? '', 'domains');

        $removed = 0;
        if ($validated['mode'] === 'replace') {
            $removed = $list->entries()->where('source', '!=', EntrySource::Request)->whereNotIn('domain', $parsed['entries'])->delete();
        }

        foreach ($parsed['entries'] as $domain) {
            $engine->upsertEntry($list, $domain, ['source' => EntrySource::Import, 'added_by_id' => $request->user()->id]);
        }

        $audit->log('policy.list_imported', $list, ['mode' => $validated['mode'], 'count' => count($parsed['entries']), 'removed' => $removed]);

        return back()->with('success', count($parsed['entries'])." hostname(s) imported, {$removed} removed.".($parsed['notes'] ? ' '.implode(' ', $parsed['notes']) : ''));
    }

    public function export(PolicyList $list): Response
    {
        $domains = $list->entries()->effective()->orderBy('domain')->pluck('domain')->implode("\n");

        return response($domains."\n", 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.str($list->name)->slug().'.txt"',
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::enum(ListKind::class)],
            'scope' => ['required', Rule::enum(ListScope::class)],
            'audience' => ['nullable', 'required_if:scope,audience', Rule::enum(Audience::class)],
            'filter_policy_id' => ['nullable', 'required_if:scope,policy', 'exists:filter_policies,id'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $scope = ListScope::from($validated['scope']);

        return [
            'name' => $validated['name'],
            'kind' => $validated['kind'],
            'scope' => $scope,
            'audience' => $scope === ListScope::Audience ? $validated['audience'] : null,
            'filter_policy_id' => $scope === ListScope::Policy ? $validated['filter_policy_id'] : null,
            'description' => $validated['description'] ?? null,
            'is_default' => $request->boolean('is_default'),
        ];
    }
}
