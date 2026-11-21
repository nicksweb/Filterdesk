<?php

namespace App\Services\Policy;

use App\Enums\EntrySource;
use App\Enums\ListKind;
use App\Enums\ListScope;
use App\Models\FilterPolicy;
use App\Models\PolicyEntry;
use App\Models\PolicyList;
use App\Services\Domains\DomainCategorizer;
use App\Support\Reach;
use Illuminate\Support\Collection;

/**
 * Works out the least change that gives access to a site: an allow entry is
 * only needed to override something (a blocked UniFi category, a blocklist
 * feed or an import from the controller, or a block in a list the approval
 * does not cover). When a site is only blocked by entries IT added (or that
 * were imported from the controller) in lists the approval covers, removing
 * those entries is enough; when nothing blocks
 * it, nothing is added. Timed access always uses an allow entry, because it
 * has to end on its own. When the category is unknown, an allow entry is the
 * safe choice.
 */
class AccessPlanner
{
    public function __construct(private readonly PolicyEngine $engine, private readonly DomainCategorizer $categorizer) {}

    /**
     * @param  list<string>  $domains
     * @return array<string, array{action: string, reason: string, entries: list<int>, lists: list<string>}>
     */
    public function plan(array $domains, Reach $scope, bool $timed): array
    {
        $policies = $this->engine->policiesFor($scope)->filter(fn (FilterPolicy $policy) => filled($policy->provider_reference))->values();
        $plan = [];
        foreach ($domains as $domain) {
            $plan[$domain] = $this->forDomain($domain, $scope, $timed, $policies);
        }

        return $plan;
    }

    /**
     * Linked policies a removed block entry applied to: their controller
     * filters must drop it on the next push.
     *
     * @param  list<int>  $entryIds
     * @return Collection<int, FilterPolicy>
     */
    public function policiesFor(array $entryIds, Reach $scope): Collection
    {
        $lists = PolicyEntry::query()->with('policyList')->whereKey($entryIds)->get()->pluck('policyList')->unique('id');

        return $this->engine->policiesFor($scope)->filter(fn (FilterPolicy $policy) => filled($policy->provider_reference)
            && $lists->contains(fn (PolicyList $list) => $list->appliesTo($policy)))->values();
    }

    /** Whether the smart plan can do anything other than add allow entries. */
    public function available(): bool
    {
        return $this->categorizer->enabled();
    }

    /**
     * @param  Collection<int, FilterPolicy>  $policies
     * @return array{action: string, reason: string, entries: list<int>, lists: list<string>}
     */
    private function forDomain(string $domain, Reach $scope, bool $timed, Collection $policies): array
    {
        $allow = fn (string $reason) => ['action' => 'allow', 'reason' => $reason, 'entries' => [], 'lists' => []];

        if ($timed) {
            return $allow('Timed access needs an allow entry that ends on its own.');
        }
        if ($policies->isEmpty()) {
            return $allow('No linked content filter to check against.');
        }
        if (! $this->categorizer->enabled()) {
            return $allow('Website categories are off, so a category block cannot be ruled out.');
        }

        $lookup = $this->categorizer->lookup($domain);
        if (! $lookup['ok'] || ($lookup['categories'] === [] && $lookup['risks'] === [])) {
            return $allow('Its category is unknown, so a category block cannot be ruled out.');
        }
        $names = $policies->pluck('name')->all();
        foreach ($lookup['unifi'] as $match) {
            $blockers = array_values(array_intersect($match['blocked_by'], $names));
            if ($blockers !== []) {
                return $allow("{$match['label']} is a blocked category in ".implode(', ', $blockers).', so an allow entry is needed.');
            }
        }
        $categories = implode(', ', $lookup['categories']);

        $blocks = $this->engine->locate($domain)
            ->filter(fn (PolicyEntry $entry) => $entry->policyList->kind === ListKind::Block && $this->isEffective($entry)
                && $policies->contains(fn (FilterPolicy $policy) => $entry->policyList->appliesTo($policy)));
        if ($blocks->isEmpty()) {
            return ['action' => 'none', 'reason' => "Categorised as {$categories}; no filter blocks that category and no list blocks the site, so nothing needs adding.", 'entries' => [], 'lists' => []];
        }

        // A feed puts its entries back on every refresh, so only an allow entry can override it.
        // An import is a single block someone made in the controller, so it can be removed like one IT added.
        $feed = $blocks->first(fn (PolicyEntry $entry) => $entry->source === EntrySource::Feed);
        if ($feed) {
            return $allow("It is in {$feed->policyList->name}, a blocklist feed that would put it back, so an allow entry overrides it.");
        }
        $outside = $blocks->first(fn (PolicyEntry $entry) => ! $this->listWithin($entry->policyList, $scope));
        if ($outside) {
            return $allow("It is blocked in {$outside->policyList->name}, which also covers networks this approval does not, so an allow entry overrides it there.");
        }

        return [
            'action' => 'unblock',
            'reason' => "Categorised as {$categories}, which no filter blocks. It is only blocked by ".$blocks->pluck('policyList.name')->unique()->join(', ')
                .($blocks->contains(fn (PolicyEntry $entry) => $entry->source === EntrySource::Import) ? ' (a block made in UniFi)' : '').', so removing that block is enough.',
            'entries' => $blocks->pluck('id')->values()->all(),
            'lists' => $blocks->pluck('policyList.name')->unique()->values()->all(),
        ];
    }

    private function isEffective(PolicyEntry $entry): bool
    {
        return ($entry->expires_at === null || $entry->expires_at->isFuture()) && ($entry->starts_at === null || $entry->starts_at->isPast());
    }

    /** Whether every network a list reaches is covered by the approval, so removing its entry affects nothing else. */
    private function listWithin(PolicyList $list, Reach $scope): bool
    {
        return match ($list->scope) {
            ListScope::Site => $scope->siteWide,
            ListScope::Audience => $scope->includes((string) $list->audience),
            ListScope::Policy => $list->filterPolicy !== null && $scope->includes((string) $list->filterPolicy->audience),
        };
    }
}
