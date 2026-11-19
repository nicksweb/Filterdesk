<?php

namespace App\Services\Policy;

use App\Models\FilterPolicy;
use App\Notifications\FilterSyncAlertNotification;
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
        $baseline = $policy->sync_baseline;
        $liveCategories = $this->sortedUnique($filter['categories'] ?? []);

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
        $networkIssues = $networks->filter(fn (array $network) => $network['policy']?->is($policy) && in_array($network['status'], ['missing_filter', 'wrong_filter', 'multiple_filters'], true))
            ->map(fn (array $network) => $network['name'].($network['vlan'] ? " (VLAN {$network['vlan']})" : '')
                .($network['status'] === 'multiple_filters' ? ' is also in '.collect($network['others'])->pluck('name')->join(', ') : ''))->values()->all();
        $sharedNetworks = $networks->filter(fn (array $network) => collect($network['attached'])->pluck('id')->contains($filter['id']) && count($network['attached']) > 1)
            ->map(fn (array $network) => $network['name'].' also uses '.collect($network['attached'])->reject(fn ($other) => $other['id'] === $filter['id'])->pluck('name')->join(', '))->values()->all();
        $networkIssues = array_values(array_unique([...$networkIssues, ...$sharedNetworks]));

        $edited = collect($changedInController)->flatten()->isNotEmpty();
        $due = collect($pending)->flatten()->isNotEmpty();
        $status = match (true) {
            $edited || $networkIssues !== [] => 'drift',
            $due => 'changes_due',
            default => 'in_sync',
        };

        $summary = [];
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
        ]);
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
