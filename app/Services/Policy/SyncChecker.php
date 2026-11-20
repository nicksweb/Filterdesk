<?php

namespace App\Services\Policy;

use App\Enums\EntrySource;
use App\Enums\ListKind;
use App\Models\FilterPolicy;
use App\Models\PolicyEntry;
use App\Models\RequestEvent;
use App\Notifications\FilterSyncAlertNotification;
use App\Services\AuditLogger;
use App\Services\Network\NetworkProviderManager;
use App\Services\Requests\RequestNotifier;
use Illuminate\Support\Collection;

/**
 * Compares every linked policy with its live controller filter and records
 * the result on the policy.
 *
 * Statuses:
 * - in_sync: the controller matches the desired lists.
 * - changes_due: this app has changes that have not been pushed yet.
 * - drift: the controller was edited directly since the last push (or since
 *   IT last accepted its state), or a mapped network uses another filter.
 * - error: the filter could not be read or no longer exists.
 *
 * Drift is measured against a baseline: the lists the controller had right
 * after the last push, or when IT accepted the controller's state.
 */
class SyncChecker
{
    public const STATUSES = ['in_sync', 'changes_due', 'drift', 'error'];

    public function __construct(
        private readonly NetworkProviderManager $providers,
        private readonly ControllerSync $sync,
        private readonly PolicyEngine $engine,
        private readonly PolicyListParser $parser,
        private readonly RequestNotifier $notifier,
    ) {}

    /**
     * Check every enabled, linked policy against one fresh read of the controller.
     *
     * @return Collection<int, FilterPolicy>
     */
    public function checkAll(bool $notify = true): Collection
    {
        $policies = FilterPolicy::query()->where('enabled', true)->whereNotNull('provider_reference')->ordered()->get();
        if ($policies->isEmpty() || ! $this->sync->available()) {
            return collect();
        }

        $state = $this->sync->state(true);
        $networks = $state['error'] ? collect() : $this->sync->networkReport($state);
        $alerts = [];

        foreach ($policies as $policy) {
            $previous = $policy->sync_status;
            $this->check($policy, $state, $networks);
            if ($notify && in_array($policy->sync_status, ['drift', 'error'], true) && $policy->sync_status !== $previous) {
                $alerts[] = $policy;
            }
        }

        if ($alerts !== []) {
            $this->notifier->filterSyncAlert(new FilterSyncAlertNotification(array_map(fn (FilterPolicy $policy) => [
                'policy' => $policy->name, 'slug' => $policy->slug, 'status' => $policy->sync_status,
                'summary' => $policy->sync_details['summary'] ?? '',
            ], $alerts)));
        }

        return $policies;
    }

    /** Check one policy, reading the controller again unless it was just read. */
    public function checkOne(FilterPolicy $policy, bool $refresh = true): FilterPolicy
    {
        $state = $this->sync->state($refresh);

        return $this->check($policy, $state, $state['error'] ? collect() : $this->sync->networkReport($state));
    }

    /**
     * Record the controller's current lists as correct, so later edits in
     * the controller are reported as drift from this point.
     *
     * @param  list<string>  $allow
     * @param  list<string>  $block
     */
    public function setBaseline(FilterPolicy $policy, array $allow, array $block, ?array $categories = null): void
    {
        $previous = $policy->sync_baseline ?? [];
        $policy->forceFill(['sync_baseline' => [
            'allow' => $this->normalise($allow),
            'block' => $this->normalise($block),
            'categories' => $categories !== null ? $this->sortedUnique($categories) : ($previous['categories'] ?? null),
            'at' => now()->toIso8601String(),
        ]])->save();
    }

    /** @param list<string> $categories */
    public function setCategoryBaseline(FilterPolicy $policy, array $categories): void
    {
        $policy->forceFill(['sync_baseline' => ['categories' => $this->sortedUnique($categories)] + ($policy->sync_baseline ?? [])])->save();
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function sortedUnique(array $values): array
    {
        return collect($values)->map(fn ($value) => (string) $value)->unique()->sort()->values()->all();
    }

    /** @param array<string, mixed> $state */
    private function check(FilterPolicy $policy, array $state, Collection $networks): FilterPolicy
    {
        if ($state['error']) {
            return $this->record($policy, 'error', ['summary' => $state['error']]);
        }

        $filter = $this->sync->filterFor($policy, $state);
        if (! $filter) {
            return $this->record($policy, 'error', ['summary' => 'The linked controller filter no longer exists. Re-link it under Networks & filters.']);
        }

        $this->sync->pull($policy, null, refresh: false);
        $policy->refresh();
        $live = ['allow' => $this->normalise($filter['allow']), 'block' => $this->normalise($filter['block'])];

        if (! isset($policy->sync_baseline['allow'])) {
            $this->setBaseline($policy, $filter['allow'], $filter['block'], $policy->sync_baseline['categories'] ?? $filter['categories'] ?? []);
        } elseif (($policy->sync_baseline['categories'] ?? null) === null) {
            $this->setCategoryBaseline($policy, $filter['categories'] ?? []);
        }
        $liveCategories = $this->sortedUnique($filter['categories'] ?? []);
        $pulled = $this->authoritative() ? $this->absorb($policy->refresh(), $filter, $live, $liveCategories) : null;
        $baseline = $policy->refresh()->sync_baseline;

        $changedInController = [
            'allow_added' => array_values(array_diff($live['allow'], $baseline['allow'] ?? [])),
            'allow_removed' => array_values(array_diff($baseline['allow'] ?? [], $live['allow'])),
            'block_added' => array_values(array_diff($live['block'], $baseline['block'] ?? [])),
            'block_removed' => array_values(array_diff($baseline['block'] ?? [], $live['block'])),
            'categories_added' => array_values(array_diff($liveCategories, $baseline['categories'] ?? [])),
            'categories_removed' => array_values(array_diff($baseline['categories'] ?? [], $liveCategories)),
        ];
        $report = $this->engine->reconcile($policy);
        $pending = [
            'allow_add' => $report['allow_add'], 'allow_remove' => $report['allow_remove'],
            'block_add' => $report['block_add'], 'block_remove' => $report['block_remove'],
        ];
        $owner = $policy->isLayer() ? $policy->layerOf : $policy;
        $networkIssues = $networks->filter(fn (array $network) => $owner && $network['policy']?->is($owner) && in_array($network['status'], ['missing_filter', 'wrong_filter', 'multiple_filters', 'layer_missing'], true))
            ->map(fn (array $network) => $network['name'].($network['vlan'] ? " (VLAN {$network['vlan']})" : '').match ($network['status']) {
                'multiple_filters' => ' is also in '.collect($network['others'])->pluck('name')->join(', '),
                'layer_missing' => ' is missing the layer '.($network['layer']->provider_name ?: $network['layer']->name),
                default => '',
            })->values()->all();
        // The parent and its layer share networks by design.
        $partners = array_filter([$owner?->provider_reference, $owner?->activeLayer()?->provider_reference]);
        $sharedNetworks = $networks->filter(fn (array $network) => collect($network['attached'])->pluck('id')->contains($filter['id'])
            && collect($network['attached'])->pluck('id')->diff([$filter['id'], ...$partners])->isNotEmpty())
            ->map(fn (array $network) => $network['name'].' also uses '.collect($network['attached'])->reject(fn ($other) => in_array($other['id'], [$filter['id'], ...$partners], true))->pluck('name')->join(', '))->values()->all();
        $networkIssues = array_values(array_unique([...$networkIssues, ...$sharedNetworks]));

        $edited = collect($changedInController)->flatten()->isNotEmpty();
        $due = collect($pending)->flatten()->isNotEmpty();
        $status = match (true) {
            $edited || $networkIssues !== [] => 'drift',
            $due => 'changes_due',
            default => 'in_sync',
        };

        $summary = [];
        if ($pulled && collect($pulled)->flatten()->isNotEmpty()) {
            $summary[] = 'Pulled from the controller: '.$this->count($pulled, ['allow_imported' => 'allowed +', 'block_imported' => 'blocked +', 'allow_excluded' => 'allow removed ', 'block_excluded' => 'block removed ', 'restored' => 're-added ', 'categories_changed' => 'categories changed ']).'.';
        }
        if ($edited) {
            $summary[] = 'Changed in the controller: '.$this->count($changedInController, ['allow_added' => 'allow +', 'allow_removed' => 'allow −', 'block_added' => 'block +', 'block_removed' => 'block −', 'categories_added' => 'categories +', 'categories_removed' => 'categories −']).'.';
        }
        if ($networkIssues) {
            $summary[] = 'Network filter issues: '.implode('; ', $networkIssues).'.';
        }
        if ($due) {
            $summary[] = 'Waiting to push: '.$this->count($pending, ['allow_add' => 'allow +', 'allow_remove' => 'allow −', 'block_add' => 'block +', 'block_remove' => 'block −']).'.';
        }

        return $this->record($policy, $status, [
            'summary' => $summary ? implode(' ', $summary) : "Matches {$filter['name']}.",
            'filter' => $filter['name'],
            'changed_in_controller' => $changedInController,
            'pending' => $pending,
            'networks' => $networkIssues,
            'unmanaged' => ['allow' => $report['allow_review'], 'block' => $report['block_review']],
            'pulled' => $pulled && collect($pulled)->flatten()->isNotEmpty() ? $pulled + ['at' => now()->toIso8601String()] : ($policy->sync_details['pulled'] ?? null),
            'authoritative' => $pulled !== null,
        ]);
    }

    /** UniFi is the source of truth unless an admin turned it off. */
    public function authoritative(): bool
    {
        return (bool) ($this->providers->integration()?->configuration['controller_authoritative'] ?? true);
    }

    /**
     * Bring the controller's state into this app: entries added there are
     * imported into the policy's own lists, managed entries removed there
     * are kept out of this policy, and the controller's lists and categories
     * become the new baseline. Changes this app still intends to make (for
     * example removing an expired approval) are left for the next push.
     *
     * @param  array<string, mixed>  $filter
     * @param  array{allow: list<string>, block: list<string>}  $live
     * @param  list<string>  $liveCategories
     * @return array{allow_imported: list<string>, block_imported: list<string>, allow_excluded: list<string>, block_excluded: list<string>, restored: list<string>, categories_changed: list<string>}
     */
    private function absorb(FilterPolicy $policy, array $filter, array $live, array $liveCategories): array
    {
        $baseline = $policy->sync_baseline ?? [];
        $desired = $this->engine->desired($policy);
        $report = $this->engine->reconcile($policy);
        $rules = $this->engine->rules();
        $result = ['allow_imported' => [], 'block_imported' => [], 'allow_excluded' => [], 'block_excluded' => [], 'restored' => [], 'categories_changed' => []];

        $exclusions = $policy->exclusions();
        $sets = [];
        foreach (['allow', 'block'] as $kind) {
            $sets[$kind] = [
                'live' => array_flip($live[$kind]),
                'baseline' => array_flip($baseline[$kind] ?? []),
                'desired' => array_flip($desired[$kind]),
                'review' => array_flip($report["{$kind}_review"]),
            ];
        }

        foreach (['allow', 'block'] as $kind) {
            foreach ($exclusions[$kind] as $domain) {
                if (isset($sets[$kind]['live'][$domain]) && ! isset($sets[$kind]['baseline'][$domain])) {
                    $exclusions[$kind] = array_values(array_diff($exclusions[$kind], [$domain]));
                    $result['restored'][] = $domain;
                }
            }
        }

        $written = $policy->managed();
        foreach (['allow', 'block'] as $kind) {
            $excluded = array_flip($exclusions[$kind]);
            $writtenByApp = array_flip($written[$kind]);
            foreach ($live[$kind] as $domain) {
                $wanted = $kind === 'allow'
                    ? $this->engine->coveredBy($sets['allow']['desired'], $domain, $rules)
                    : isset($sets['block']['desired'][$domain]);
                if ($wanted || isset($excluded[$domain]) || isset($writtenByApp[$domain])) {
                    continue;
                }
                if (! isset($sets[$kind]['baseline'][$domain]) || isset($sets[$kind]['review'][$domain])) {
                    $result["{$kind}_imported"][] = $domain;
                }
            }

            foreach (array_diff($baseline[$kind] ?? [], $live[$kind]) as $domain) {
                if (isset($sets[$kind]['desired'][$domain]) && ! isset($excluded[$domain])) {
                    $exclusions[$kind][] = $domain;
                    $result["{$kind}_excluded"][] = $domain;
                }
            }
        }

        $excludedAllow = array_flip($exclusions['allow']);
        foreach ($result['block_imported'] as $domain) {
            if (isset($sets['allow']['desired'][$domain]) && ! isset($excludedAllow[$domain])) {
                $exclusions['allow'][] = $domain;
                $result['allow_excluded'][] = $domain;
            }
        }

        foreach ([[ListKind::Allow, 'allow_imported'], [ListKind::Block, 'block_imported']] as [$kind, $key]) {
            if ($result[$key] === []) {
                continue;
            }
            $list = $this->engine->ownList($policy, $kind, "Entries added in the controller filter {$filter['name']}.");
            foreach ($result[$key] as $domain) {
                $this->engine->upsertEntry($list, $domain, ['source' => EntrySource::Import, 'note' => "Added in the controller ({$filter['name']})"]);
            }
        }

        if ($result['allow_excluded'] !== []) {
            $this->noteRemovedApprovals($policy, $filter['name'], $result['allow_excluded']);
        }

        if (isset($baseline['categories']) && $baseline['categories'] !== $liveCategories) {
            $result['categories_changed'] = array_values(array_merge(
                array_map(fn ($category) => "+{$category}", array_diff($liveCategories, $baseline['categories'])),
                array_map(fn ($category) => "−{$category}", array_diff($baseline['categories'], $liveCategories)),
            ));
        }

        $policy->forceFill([
            'controller_exclusions' => ['allow' => array_values(array_unique($exclusions['allow'])), 'block' => array_values(array_unique($exclusions['block']))],
            // Entries the app wrote that are no longer in the controller are no longer the app's to remove.
            'controller_managed' => [
                'allow' => array_values(array_filter($written['allow'], fn (string $domain) => isset($sets['allow']['live'][$domain]))),
                'block' => array_values(array_filter($written['block'], fn (string $domain) => isset($sets['block']['live'][$domain]))),
            ],
        ])->save();
        $this->setBaseline($policy, $live['allow'], $live['block'], $liveCategories);

        if (collect($result)->flatten()->isNotEmpty()) {
            app(AuditLogger::class)->log('policy.controller_changes_pulled', $policy, ['filter' => $filter['name']] + $result);
        }

        return $result;
    }

    /**
     * Tell IT on each affected request that the controller no longer
     * allows some of its hostnames.
     *
     * @param  list<string>  $domains
     */
    private function noteRemovedApprovals(FilterPolicy $policy, string $filterName, array $domains): void
    {
        PolicyEntry::query()->with('policyList')->whereIn('domain', $domains)->where('source', EntrySource::Request)->whereNotNull('website_request_id')->get()
            ->filter(fn (PolicyEntry $entry) => $entry->policyList->appliesTo($policy))
            ->groupBy('website_request_id')
            ->each(fn ($entries, $requestId) => RequestEvent::query()->create([
                'website_request_id' => $requestId, 'type' => 'controller_removed', 'internal' => true,
                'message' => 'Removed from the controller filter '.$filterName.' by hand: '.$entries->pluck('domain')->unique()->join(', ').". It is kept out of {$policy->name} until IT restores it on the policy page.",
            ]));
    }

    /** @param array<string, mixed> $details */
    private function record(FilterPolicy $policy, string $status, array $details): FilterPolicy
    {
        $policy->forceFill(['sync_status' => $status, 'sync_checked_at' => now(), 'sync_details' => $details])->save();

        return $policy;
    }

    /**
     * @param  array<string, list<string>>  $groups
     * @param  array<string, string>  $labels
     */
    private function count(array $groups, array $labels): string
    {
        return collect($labels)->filter(fn ($label, $key) => ! empty($groups[$key]))->map(fn ($label, $key) => $label.count($groups[$key]))->join(', ');
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function normalise(array $values): array
    {
        return collect($values)->map(fn ($value) => $this->parser->normalise((string) $value))->filter()->unique()->sort()->values()->all();
    }
}
