<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EntrySource;
use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Models\FilterPolicy;
use App\Models\PolicyEntry;
use App\Models\PolicyList;
use App\Models\WebsiteRequest;
use App\Services\AuditLogger;
use App\Services\Policy\PolicyEngine;
use App\Services\Policy\PolicyListParser;
use App\Services\SettingsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Overview of every filter policy and list, plus quick add and lookup. */
class PolicyController extends Controller
{
    public function index(Request $request, PolicyEngine $engine, PolicyListParser $parser): View
    {
        $policies = FilterPolicy::query()->ordered()->with('networkProfile')->get();
        $lookup = null;
        if (filled($request->query('lookup'))) {
            $domain = $parser->normalise((string) $request->query('lookup'));
            $lookup = ['domain' => $domain, 'entries' => $parser->valid($domain) ? $engine->locate($domain) : collect(), 'valid' => $parser->valid($domain)];
        }

        return view('admin.policy.index', [
            'policies' => $policies->map(fn (FilterPolicy $policy) => ['policy' => $policy, 'report' => $engine->reconcile($policy)]),
            'lists' => PolicyList::query()->ordered()->withCount(['entries', 'entries as request_entries_count' => fn ($query) => $query->where('source', EntrySource::Request)])->with('filterPolicy')->get(),
            'actionRequired' => WebsiteRequest::query()->whereIn('status', [RequestStatus::Approved, RequestStatus::ActionRequired, RequestStatus::Failed])->count(),
            'upcoming' => $engine->upcoming(48),
            'lookup' => $lookup,
            'rules' => $engine->rules(),
        ]);
    }

    public function storeEntries(Request $request, PolicyListParser $parser, PolicyEngine $engine, AuditLogger $audit, SettingsRepository $settings): RedirectResponse
    {
        $validated = $request->validate([
            'policy_list_id' => ['required', 'exists:policy_lists,id'],
            'domains' => ['required', 'string', 'max:20000'],
            'note' => ['nullable', 'string', 'max:500'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
        ]);
        $startsAt = $settings->parseLocal($validated['starts_at'] ?? null);
        $expiresAt = $settings->parseLocal($validated['expires_at'] ?? null);
        if ($expiresAt && ($expiresAt->isPast() || ($startsAt && $expiresAt->lte($startsAt)))) {
            throw ValidationException::withMessages(['expires_at' => 'The expiry must be in the future and after the start.']);
        }

        $list = PolicyList::query()->findOrFail($validated['policy_list_id']);
        $parsed = $parser->parseDetailed($validated['domains'], 'domains');
        if ($parsed['entries'] === []) {
            throw ValidationException::withMessages(['domains' => 'Enter at least one hostname.']);
        }

        foreach ($parsed['entries'] as $domain) {
            $engine->upsertEntry($list, $domain, [
                'source' => EntrySource::Manual,
                'added_by_id' => $request->user()->id,
                'note' => $validated['note'] ?? null,
                'starts_at' => $startsAt,
                'expires_at' => $expiresAt,
            ]);
        }

        $audit->log('policy.entries_added', $list, ['domains' => $parsed['entries']]);

        return back()->with('success', count($parsed['entries'])." hostname(s) added to {$list->name}.".($parsed['notes'] ? ' '.implode(' ', $parsed['notes']) : ''));
    }

    public function destroyEntry(Request $request, PolicyEntry $entry, AuditLogger $audit): RedirectResponse
    {
        $entry->load('policyList');
        $audit->log('policy.entry_removed', $entry->policyList, ['domain' => $entry->domain, 'source' => $entry->source->value]);
        $entry->delete();

        return back()->with('success', "{$entry->domain} removed from {$entry->policyList->name}.");
    }
}
