<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EntrySource;
use App\Enums\ListKind;
use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Models\FilterPolicy;
use App\Models\PolicyEntry;
use App\Models\PolicyList;
use App\Models\WebsiteRequest;
use App\Services\AuditLogger;
use App\Services\Domains\PublicSuffixList;
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
            'lists' => PolicyList::query()->ordered()->with('feed:id,policy_list_id,name')->withCount(['entries', 'entries as request_entries_count' => fn ($query) => $query->where('source', EntrySource::Request)])->with('filterPolicy')->get(),
            'actionRequired' => WebsiteRequest::query()->whereIn('status', [RequestStatus::Approved, RequestStatus::ActionRequired, RequestStatus::Failed])->count(),
            'upcoming' => $engine->upcoming(48),
            'recent' => PolicyEntry::query()->with('policyList', 'websiteRequest:id,reference', 'addedBy:id,name')->latest()->limit(8)->get(),
            'lookup' => $lookup,
            'rules' => $engine->rules(),
        ]);
    }

    public function storeEntries(Request $request, PolicyListParser $parser, PolicyEngine $engine, AuditLogger $audit, SettingsRepository $settings, PublicSuffixList $suffixes): RedirectResponse
    {
        $validated = $request->validate([
            'policy_list_id' => ['required', 'exists:policy_lists,id'],
            'domains' => ['required_without:hosts', 'nullable', 'string', 'max:20000'],
            'hosts' => ['required_without:domains', 'array', 'max:1000'],
            'hosts.*' => ['string', 'max:253'],
            'note' => ['nullable', 'string', 'max:500'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'include_www' => ['nullable', 'boolean'],
        ]);
        $startsAt = $settings->parseLocal($validated['starts_at'] ?? null);
        $expiresAt = $settings->parseLocal($validated['expires_at'] ?? null);
        if ($expiresAt && ($expiresAt->isPast() || ($startsAt && $expiresAt->lte($startsAt)))) {
            throw ValidationException::withMessages(['expires_at' => 'The expiry must be in the future and after the start.']);
        }

        $list = PolicyList::query()->findOrFail($validated['policy_list_id']);
        $parsed = $parser->parseDetailed(implode("\n", [$validated['domains'] ?? '', ...($validated['hosts'] ?? [])]), 'domains');
        if ($parsed['entries'] === []) {
            throw ValidationException::withMessages(['domains' => 'Enter at least one hostname.']);
        }
        if ($list->feed()->exists()) {
            throw ValidationException::withMessages(['policy_list_id' => "{$list->name} is filled from a blocklist feed. Add hostnames to another list."]);
        }
        if ($request->boolean('include_www')) {
            $parsed['entries'] = $this->withWwwCounterparts($parsed['entries'], $suffixes);
        }

        $alreadyInList = $list->entries()->whereIn('domain', $parsed['entries'])->pluck('domain')->all();
        $elsewhere = [];
        foreach ($parsed['entries'] as $domain) {
            foreach ($engine->locate($domain) as $found) {
                if ($found->policy_list_id !== $list->id) {
                    $elsewhere[] = "{$domain} is also in {$found->policyList->name}".($found->domain !== $domain ? " (as {$found->domain})" : '');
                }
            }
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

        $added = count($parsed['entries']) - count($alreadyInList);
        $messages = ["{$added} hostname(s) added to {$list->name}."];
        if ($alreadyInList) {
            $messages[] = 'Already in this list: '.implode(', ', $alreadyInList).'.';
        }
        if ($elsewhere) {
            $messages[] = implode('; ', array_unique($elsewhere)).'.';
        }
        foreach (FilterPolicy::query()->where('enabled', true)->ordered()->get() as $policy) {
            if (! $list->appliesTo($policy)) {
                continue;
            }
            $desired = $engine->desired($policy);
            $overridden = array_values(array_filter($parsed['entries'], fn (string $domain) => ($desired['resolved'][$domain] ?? null) !== null && $desired['resolved'][$domain] !== $list->kind->value));
            if ($overridden) {
                $messages[] = "For {$policy->name}, ".implode(', ', $overridden).' stay '.($list->kind === ListKind::Allow ? 'blocked' : 'allowed').' because a more specific list overrides '.$list->name.'. Add them to a list for that policy instead, or remove the other entry.';
            }
        }
        if ($list->kind === ListKind::Allow) {
            foreach (FilterPolicy::query()->where('enabled', true)->get() as $policy) {
                $blocked = array_intersect($parsed['entries'], $policy->current_blocklist ?? []);
                if ($blocked && $list->appliesTo($policy)) {
                    $messages[] = 'The controller filter for '.$policy->name.' blocks '.implode(', ', $blocked).': remove it there when you next reconcile.';
                }
            }
        }

        return redirect()->route('admin.policy.lists.show', $list)->with('success', implode(' ', [...$messages, ...$parsed['notes']]));
    }

    /**
     * Add the www. form of a bare registrable domain, and the bare domain of
     * a www. hostname, so a site is allowed or blocked however it is typed.
     *
     * @param  list<string>  $domains
     * @return list<string>
     */
    private function withWwwCounterparts(array $domains, PublicSuffixList $suffixes): array
    {
        $result = $domains;
        foreach ($domains as $domain) {
            $bare = str_starts_with($domain, 'www.') ? substr($domain, 4) : $domain;
            if ($suffixes->registrableDomain($bare) !== $bare) {
                continue;
            }
            $result[] = $bare;
            $result[] = "www.{$bare}";
        }

        return array_values(array_unique($result));
    }

    public function destroyEntry(Request $request, PolicyEntry $entry, AuditLogger $audit): RedirectResponse
    {
        $entry->load('policyList');
        $audit->log('policy.entry_removed', $entry->policyList, ['domain' => $entry->domain, 'source' => $entry->source->value]);
        $entry->delete();

        return back()->with('success', "{$entry->domain} removed from {$entry->policyList->name}.");
    }
}
