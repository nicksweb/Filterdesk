<?php

namespace App\Services\Policy;

use App\Enums\EntrySource;
use App\Enums\ListKind;
use App\Models\FilterPolicy;
use App\Models\NetworkType;
use App\Models\PolicyEntry;
use App\Models\PolicyList;
use App\Models\User;
use App\Services\Approval\TwoPersonRule;
use App\Services\AuditLogger;
use App\Services\Domains\PublicSuffixList;
use App\Support\Reach;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Block or allow hostnames on several network types at once, without a
 * request: work out which lists they go into (a school-hours layer for a
 * block in school hours only), which entries say the opposite and would win
 * or should go, which UniFi categories already decide it, and which filters
 * change; then make the change. Site-wide changes still wait for a second
 * IT officer.
 */
class QuickChange
{
    public function __construct(
        private readonly PolicyEngine $engine,
        private readonly PolicyListParser $parser,
        private readonly PublicSuffixList $suffixes,
        private readonly CategoryCoverage $coverage,
        private readonly TwoPersonRule $twoPerson,
        private readonly AuditLogger $audit,
        private readonly ProtectedSites $protectedSites,
    ) {}

    /**
     * @param  array{domains: string, kind: string, types: list<string>, when: string, include_www?: bool}  $input
     * @return array<string, mixed>
     */
    public function plan(array $input): array
    {
        $parsed = $this->parser->parseDetailed($input['domains'], 'domains');
        $domains = ($input['include_www'] ?? false) ? $this->withWww($parsed['entries']) : $parsed['entries'];
        $kind = $input['kind'] === 'block' ? ListKind::Block : ListKind::Allow;
        $protected = [];
        if ($kind === ListKind::Block) {
            ['allowed' => $domains, 'protected' => $protected] = $this->protectedSites->partition($domains);
        }
        $scope = in_array('site', $input['types'], true) ? Reach::siteWide() : Reach::types($input['types']);
        $when = $this->when($kind, $input['when']);

        $target = $this->engine->listsForHours($scope, $kind, $when);
        $lists = collect($target['lists']);
        $policies = $this->affectedPolicies($scope, $kind, $when);
        $opposite = $kind === ListKind::Allow ? ListKind::Block : ListKind::Allow;

        // Entries that say the opposite for a policy this reaches. Bulk entries (feeds, imports) lose to
        // this change anyway; entries added on purpose can win, so they are listed to remove.
        $conflicts = collect($domains)->flatMap(fn (string $domain) => $this->engine->locate($domain)
            ->filter(fn (PolicyEntry $entry) => $entry->policyList->kind === $opposite && $entry->pending_action === null
                && $policies->contains(fn (FilterPolicy $policy) => $entry->policyList->appliesTo($policy)))
            ->map(fn (PolicyEntry $entry) => [
                'entry' => $entry, 'for' => $domain,
                'removable' => in_array($entry->source, [EntrySource::Manual, EntrySource::Import], true) && ! $entry->policyList->feed()->exists(),
                'feed' => $entry->source === EntrySource::Feed,
            ]))->unique(fn (array $conflict) => $conflict['entry']->id)->values();

        $categories = collect($this->coverage->forDomains($domains))
            ->map(fn (array $hits) => collect($hits)->filter(fn (array $hit) => $policies->contains(fn (FilterPolicy $policy) => $policy->is($hit['policy']))));

        return [
            'domains' => $domains,
            'notes' => $protected ? [...$parsed['notes'], ProtectedSites::message($protected)] : $parsed['notes'],
            'protected' => $protected,
            'kind' => $kind,
            'scope' => $scope,
            'when' => $when,
            'lists' => $lists,
            'skipped' => collect($target['skipped'])->map(fn (string $type) => NetworkType::labelFor($type))->all(),
            'filters' => $policies->filter(fn (FilterPolicy $policy) => filled($policy->provider_reference))->map(fn (FilterPolicy $policy) => [
                'name' => $policy->provider_name ?: $policy->name, 'schedule' => $policy->sync_details['schedule'] ?? null, 'school_hours' => $policy->isHoursLayer(),
            ])->values()->all(),
            'conflicts' => $conflicts->all(),
            'categories' => $categories->filter(fn (Collection $hits) => $hits->isNotEmpty())->all(),
            'already' => collect($domains)->filter(fn (string $domain) => $lists->every(fn (PolicyList $list) => $list->entries()->where('domain', $domain)->exists()))->values()->all(),
            'two_person' => $lists->contains(fn (PolicyList $list) => $this->twoPerson->appliesToList($list)),
        ];
    }

    /**
     * Make the change the plan describes, removing the chosen conflicting
     * entries.
     *
     * @param  array<string, mixed>  $plan
     * @param  list<int>  $remove  ids of conflicting entries to remove
     * @return array{added: int, proposed: int, removed: int, proposed_removals: int}
     */
    public function apply(array $plan, array $remove, User $actor, ?string $note, ?CarbonInterface $expiresAt): array
    {
        $counts = ['added' => 0, 'proposed' => 0, 'removed' => 0, 'proposed_removals' => 0];
        DB::transaction(function () use ($plan, $remove, $actor, $note, $expiresAt, &$counts): void {
            foreach ($plan['lists'] as $list) {
                $proposal = $this->twoPerson->appliesToList($list);
                foreach ($plan['domains'] as $domain) {
                    $this->engine->upsertEntry($list, $domain, [
                        'source' => EntrySource::Manual,
                        'added_by_id' => $actor->id,
                        'note' => $note ?: 'Quick '.($plan['kind'] === ListKind::Block ? 'block' : 'allow').' by '.$actor->name.($plan['when'] === 'school' ? ' (school hours only)' : ''),
                        'expires_at' => $expiresAt,
                        'hours' => $plan['when'] === 'outside' ? 'outside' : null,
                    ] + ($proposal ? ['pending_action' => 'add', 'proposed_by_id' => $actor->id, 'proposed_at' => now()] : []));
                    $counts[$proposal ? 'proposed' : 'added']++;
                }
            }

            foreach ($plan['conflicts'] as $conflict) {
                $entry = $conflict['entry'];
                if (! $conflict['removable'] || ! in_array($entry->id, $remove, true)) {
                    continue;
                }
                if ($this->twoPerson->appliesToList($entry->policyList)) {
                    $entry->forceFill(['pending_action' => 'remove', 'proposed_by_id' => $actor->id, 'proposed_at' => now()])->save();
                    $counts['proposed_removals']++;
                } else {
                    $entry->delete();
                    $counts['removed']++;
                }
            }
        });

        $this->audit->log('policy.quick_change', null, [
            'kind' => $plan['kind']->value, 'domains' => $plan['domains'], 'scope' => $plan['scope']->value(), 'when' => $plan['when'] ?? 'all',
            'lists' => collect($plan['lists'])->pluck('name')->all(), 'removed' => $remove, 'by' => $actor->id,
        ]);

        return $counts;
    }

    /** Only the choices that make sense: a block in school hours only, or an allow outside them. */
    private function when(ListKind $kind, string $when): ?string
    {
        return match (true) {
            $kind === ListKind::Block && $when === 'school' => 'school',
            $kind === ListKind::Allow && $when === 'outside' => 'outside',
            default => null,
        };
    }

    /**
     * The policies (filters) the change reaches: each type's policies and
     * their layers, or only the school-hours layers for a school-hours block.
     *
     * @return Collection<int, FilterPolicy>
     */
    private function affectedPolicies(Reach $scope, ListKind $kind, ?string $when): Collection
    {
        if ($kind === ListKind::Block && $when === 'school') {
            $types = $scope->siteWide ? NetworkType::query()->pluck('key')->all() : $scope->types;

            return collect($types)->flatMap(fn (string $type) => $this->engine->hoursLayersFor($type))->unique('id')->values();
        }

        return $this->engine->policiesFor($scope)
            ->flatMap(fn (FilterPolicy $policy) => array_filter([$policy, $policy->activeLayer(), $when === 'outside' ? null : $policy->activeHoursLayer()]))
            ->unique('id')->values();
    }

    /**
     * @param  list<string>  $domains
     * @return list<string>
     */
    private function withWww(array $domains): array
    {
        $result = $domains;
        foreach ($domains as $domain) {
            $bare = str_starts_with($domain, 'www.') ? substr($domain, 4) : $domain;
            if ($this->suffixes->registrableDomain($bare) === $bare) {
                $result[] = $bare;
                $result[] = "www.{$bare}";
            }
        }

        return array_values(array_unique($result));
    }
}
