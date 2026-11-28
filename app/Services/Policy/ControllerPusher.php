<?php

namespace App\Services\Policy;

use App\Models\ControllerPush;
use App\Models\FilterPolicy;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Network\NetworkProviderManager;
use App\Services\Network\ProviderRequestFailed;
use App\Services\Network\WritesContentFilters;
use App\Services\Requests\RequestWorkflow;
use App\Support\Reach;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Applies each filter policy's change set to its linked controller content
 * filter.
 *
 * A push is a change set, never an overwrite: the live filter is re-read,
 * managed additions are appended and managed removals taken out, and every
 * entry added in the controller by hand is kept exactly as written. Each
 * write is locked per filter, read back by the provider, recorded with the
 * lists before and after, and can be rolled back.
 */
class ControllerPusher
{
    /** Default for how many changes an automatic push (approvals, schedules, feed refreshes) may make before it waits for a person. */
    public const AUTOMATIC_CHANGE_LIMIT = 1000;

    /** The largest content-filter list UniFi is reported to handle. */
    public const DEFAULT_MAX_ENTRIES = 100000;

    /** Above this, the UniFi web interface gets slow to open and edit the filter. */
    public const SLOW_LIST_ENTRIES = 5000;

    /** Largest allow or block list a push may write to one controller filter. */
    public function maxAutomaticChanges(): int
    {
        return (int) ($this->providers->integration()?->configuration['max_automatic_changes'] ?? self::AUTOMATIC_CHANGE_LIMIT);
    }

    public function maxEntries(): int
    {
        return (int) ($this->providers->integration()?->configuration['max_filter_entries'] ?? self::DEFAULT_MAX_ENTRIES);
    }

    public function __construct(
        private readonly NetworkProviderManager $providers,
        private readonly ControllerSync $sync,
        private readonly PolicyEngine $engine,
        private readonly PolicyListParser $parser,
        private readonly AuditLogger $audit,
    ) {}

    public function writer(): ?WritesContentFilters
    {
        try {
            $provider = $this->providers->current();
        } catch (Throwable) {
            return null;
        }

        return $provider instanceof WritesContentFilters && ($provider->capabilities()['content_filter_write'] ?? false) ? $provider : null;
    }

    /** Writes are allowed: the provider supports them and the integration is in active mode. */
    public function enabled(): bool
    {
        return $this->writer() !== null && $this->providers->integration()?->mode === 'active';
    }

    /** Push automatically after approvals, revocations, expiries and list edits. */
    public function automatic(): bool
    {
        return $this->enabled() && (bool) ($this->providers->integration()?->configuration['auto_push'] ?? false);
    }

    /**
     * What a push would change, computed against the live filter.
     *
     * @return array{ok: bool, message: string, filter?: array<string, mixed>, changes?: array{allow_add: list<string>, allow_remove: list<string>, block_add: list<string>, block_remove: list<string>}, allow?: list<string>, block?: list<string>}
     */
    public function plan(FilterPolicy $policy, ?User $actor = null, bool $refresh = true): array
    {
        if (blank($policy->provider_reference)) {
            return ['ok' => false, 'message' => "{$policy->name} is not linked to a controller content filter."];
        }
        if (! $policy->enabled) {
            return ['ok' => false, 'message' => "{$policy->name} is disabled."];
        }
        $sharing = FilterPolicy::query()->whereKeyNot($policy->id)->where('enabled', true)->where('provider_reference', $policy->provider_reference)->pluck('name');
        if ($sharing->isNotEmpty()) {
            return ['ok' => false, 'message' => "{$policy->name} shares its controller filter with {$sharing->join(', ')}. Link each filter to one policy only."];
        }

        // Read the live filter and, with the controller as the source of
        // truth, pull in anything changed there before working out the push.
        $checked = $this->checker()->checkOne($policy, $refresh);
        if ($checked->sync_status === 'error') {
            return ['ok' => false, 'message' => $checked->sync_details['summary'] ?? 'The controller filter could not be read.'];
        }

        $filter = $this->sync->filterFor($policy->refresh());
        $report = $this->engine->reconcile($policy);
        $changes = [
            'allow_add' => $report['allow_add'],
            'allow_remove' => $report['allow_remove'],
            'block_add' => $report['block_add'],
            'block_remove' => $report['block_remove'],
        ];

        return [
            'ok' => true,
            'message' => $this->describe($changes),
            'filter' => $filter,
            'changes' => $changes,
            'allow' => $this->apply($filter['allow'], $changes['allow_add'], $changes['allow_remove']),
            'block' => $this->apply($filter['block'], $changes['block_add'], $changes['block_remove']),
        ];
    }

    /** @return array{ok: bool, message: string, push?: ControllerPush} */
    public function push(FilterPolicy $policy, ?User $actor, string $trigger = 'manual', bool $reviewed = false): array
    {
        if (! $this->enabled()) {
            return ['ok' => false, 'message' => 'Pushing is off. An admin can turn it on under Settings → Network provider (Mode: Active).'];
        }

        // Every write belongs to a policy version (joining one already open, such as "publish everything").
        return $this->publisher()->within($trigger, $actor, function () use ($policy, $actor, $trigger, $reviewed): array {
            try {
                return Cache::lock('controller-push:'.$policy->provider_reference, 60)->block(30, fn () => $this->pushLocked($policy, $actor, $trigger, $reviewed));
            } catch (LockTimeoutException) {
                return ['ok' => false, 'message' => "Another push to {$policy->name} is still running. Try again in a minute."];
            }
        });
    }

    private function publisher(): PolicyPublisher
    {
        return app(PolicyPublisher::class);
    }

    /**
     * Push every linked policy, or only those for a scope.
     *
     * @return Collection<int, array{policy: FilterPolicy, ok: bool, message: string}>
     */
    public function pushAll(?User $actor, string $trigger = 'manual', ?Reach $scope = null): Collection
    {
        return $this->publisher()->within($trigger, $actor, fn (): Collection => $this->pushAllNow($actor, $trigger, $scope));
    }

    /** @return Collection<int, array{policy: FilterPolicy, ok: bool, message: string}> */
    private function pushAllNow(?User $actor, string $trigger, ?Reach $scope): Collection
    {
        $this->sync->forget();
        $policies = $scope
            ? $this->engine->policiesFor($scope)->flatMap(fn (FilterPolicy $policy) => array_filter([$policy->activeLayer(), $policy->activeHoursLayer(), $policy]))
            : FilterPolicy::query()->where('enabled', true)->ordered()->get();
        // Layers go first: a feed moving out of a parent is blocked in its layer before it leaves the parent.
        $policies = $policies->filter(fn (FilterPolicy $policy) => filled($policy->provider_reference))
            ->sortBy(fn (FilterPolicy $policy) => $policy->isLayer() ? 0 : 1)->values();

        // A policy whose filter was deleted in UniFi is skipped with a note, not counted as a failure.
        $state = $this->sync->state();
        $filterIds = $state['error'] ? null : array_column($state['filters'], 'id');

        return $policies->map(fn (FilterPolicy $policy) => ['policy' => $policy] + ($filterIds !== null && ! in_array($policy->provider_reference, $filterIds, true)
            ? ['ok' => true, 'skipped' => true, 'message' => "{$policy->name} skipped: its UniFi filter ".($policy->provider_name ?: $policy->provider_reference).' was deleted.']
            : $this->push($policy, $actor, $trigger)))->values();
    }

    /**
     * Put a filter back to the lists it had before a push, provided nobody
     * has changed it since.
     *
     * @return array{ok: bool, message: string}
     */
    public function rollback(ControllerPush $push, ?User $actor): array
    {
        return $this->publisher()->within('rollback', $actor, fn (): array => $this->rollbackNow($push, $actor));
    }

    /** @return array{ok: bool, message: string} */
    private function rollbackNow(ControllerPush $push, ?User $actor): array
    {
        $writer = $this->writer();
        if (! $writer || ! $this->enabled()) {
            return ['ok' => false, 'message' => 'Pushing is off, so the change cannot be rolled back from here.'];
        }
        if ($push->status !== 'ok' || $push->rollback_of_id !== null) {
            return ['ok' => false, 'message' => 'Only a successful push can be rolled back.'];
        }

        $this->sync->forget();
        $live = collect($this->sync->state(true)['filters'])->firstWhere('id', $push->provider_reference);
        if (! $live) {
            return ['ok' => false, 'message' => 'That content filter is no longer in the controller.'];
        }
        if ($this->sameLists($live['allow'], $push->after_allow) === false || $this->sameLists($live['block'], $push->after_block) === false) {
            return ['ok' => false, 'message' => 'The filter has changed since this push, so rolling back would undo later changes. Push again from the policy instead.'];
        }

        try {
            $writer->writeContentFilterLists($push->provider_reference, $push->before_allow, $push->before_block);
        } catch (ProviderRequestFailed $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }

        if ($push->filterPolicy) {
            $managed = $push->filterPolicy->managed();
            $push->filterPolicy->forceFill(['controller_managed' => [
                'allow' => array_values(array_diff($managed['allow'], $push->changes['allow_add'] ?? [])),
                'block' => array_values(array_diff($managed['block'], $push->changes['block_add'] ?? [])),
            ]])->save();
        }
        ControllerPush::query()->create([
            'policy_release_id' => $this->publisher()->current()?->id,
            'filter_policy_id' => $push->filter_policy_id, 'user_id' => $actor?->id, 'provider_reference' => $push->provider_reference,
            'provider_name' => $push->provider_name, 'trigger' => 'rollback', 'status' => 'ok',
            'before_allow' => $live['allow'], 'before_block' => $live['block'],
            'after_allow' => $push->before_allow, 'after_block' => $push->before_block,
            'changes' => ['allow_add' => [], 'allow_remove' => [], 'block_add' => [], 'block_remove' => []],
            'message' => "Rolled back push #{$push->id}.", 'rollback_of_id' => $push->id,
        ]);
        $this->sync->forget();
        if ($push->filterPolicy) {
            $this->sync->pull($push->filterPolicy, $actor);
            $this->checker()->setBaseline($push->filterPolicy, $push->before_allow, $push->before_block);
            $this->checker()->checkOne($push->filterPolicy->refresh(), refresh: false);
        }
        $this->audit->log('policy.controller_rolled_back', $push->filterPolicy, ['push_id' => $push->id]);

        return ['ok' => true, 'message' => "{$push->provider_name} is back to its lists from before push #{$push->id}. Entries managed here will show as changes due until you push again or change the lists."];
    }

    /**
     * Put a content filter's lists back to an earlier version (Changes →
     * Restore to a point in time). Protected sites are still never blocked.
     * The policy then follows what is in UniFi; anything a push would still
     * change puts the policy on hold, so an automatic push can't undo the
     * restore before someone has looked.
     *
     * @param  list<string>  $allow
     * @param  list<string>  $block
     * @return array{ok: bool, message: string}
     */
    public function restoreLists(string $reference, array $allow, array $block, ?User $actor, string $when): array
    {
        return $this->publisher()->within('restore', $actor, function () use ($reference, $allow, $block, $actor, $when): array {
            $writer = $this->writer();
            if (! $writer || ! $this->enabled()) {
                return ['ok' => false, 'message' => 'Pushing is off (Settings → Network provider → Mode), so content filters cannot be restored from here.'];
            }
            $this->sync->forget();
            $live = collect($this->sync->state(true)['filters'])->firstWhere('id', $reference);
            if (! $live) {
                return ['ok' => false, 'message' => 'That content filter is no longer in the controller.'];
            }
            $block = app(ProtectedSites::class)->partition(array_values($block))['allowed'];
            $allow = array_values($allow);
            if ($this->sameLists($live['allow'], $allow) && $this->sameLists($live['block'], $block)) {
                return ['ok' => true, 'message' => "{$live['name']}: already as it was."];
            }

            try {
                $writer->writeContentFilterLists($reference, $allow, $block);
            } catch (ProviderRequestFailed $exception) {
                return ['ok' => false, 'message' => "{$live['name']}: {$exception->getMessage()}"];
            }

            $policy = FilterPolicy::query()->where('provider_reference', $reference)->first();
            $changes = [
                'allow_add' => array_values(array_diff($allow, $live['allow'])), 'allow_remove' => array_values(array_diff($live['allow'], $allow)),
                'block_add' => array_values(array_diff($block, $live['block'])), 'block_remove' => array_values(array_diff($live['block'], $block)),
            ];
            ControllerPush::query()->create([
                'policy_release_id' => $this->publisher()->current()?->id,
                'filter_policy_id' => $policy?->id, 'user_id' => $actor?->id, 'provider_reference' => $reference,
                'provider_name' => $live['name'], 'trigger' => 'restore', 'status' => 'ok',
                'before_allow' => $live['allow'], 'before_block' => $live['block'], 'after_allow' => $allow, 'after_block' => $block,
                'changes' => $changes, 'message' => "Restored to how it was at {$when}.",
            ]);
            $held = false;
            if ($policy) {
                $managed = $policy->managed();
                $policy->forceFill(['controller_managed' => [
                    'allow' => array_values(array_intersect($managed['allow'], $allow)),
                    'block' => array_values(array_intersect($managed['block'], $block)),
                ]])->save();
                $this->sync->forget();
                $this->sync->pull($policy, $actor);
                $this->checker()->setBaseline($policy, $allow, $block);
                $this->checker()->checkOne($policy->refresh(), refresh: false);
                $plan = $this->plan($policy->refresh(), $actor, refresh: false);
                if (($plan['ok'] ?? false) && collect($plan['changes'] ?? [])->flatten()->isNotEmpty()) {
                    $policy->forceFill(['push_hold' => "Restored to how it was at {$when}; its lists still differ from that."])->save();
                    $held = true;
                }
            }
            $this->audit->log('policy.controller_restored', $policy, ['filter' => $live['name'], 'reference' => $reference, 'at' => $when,
                'counts' => array_map('count', $changes)]);

            return ['ok' => true, 'message' => "{$live['name']}: restored (allow +".count($changes['allow_add']).' / −'.count($changes['allow_remove'])
                .', block +'.count($changes['block_add']).' / −'.count($changes['block_remove']).').'
                .($held ? " {$policy->name} is on hold until someone checks it and pushes." : '')];
        });
    }

    /** @return array{ok: bool, message: string, push?: ControllerPush} */
    private function pushLocked(FilterPolicy $policy, ?User $actor, string $trigger, bool $reviewed = false): array
    {
        // After a link change nothing goes to the filter until a person pushes this policy on its own.
        if (filled($policy->push_hold) && ! $reviewed) {
            return ['ok' => false, 'held' => true, 'message' => "{$policy->name} is on hold: {$policy->push_hold} Check the plan and push it from the policy page."];
        }
        $plan = $this->plan($policy, $actor);
        if (! $plan['ok']) {
            return $plan;
        }

        if (collect($plan['changes'])->flatten()->isEmpty()) {
            if ($reviewed && filled($policy->push_hold)) {
                $policy->forceFill(['push_hold' => null])->save();
            }
            $this->checker()->checkOne($policy->refresh(), refresh: false);
            app(RequestWorkflow::class)->settlePushed($actor);

            return ['ok' => true, 'message' => "{$plan['filter']['name']} already matches {$policy->name}."];
        }

        $limit = $this->maxEntries();
        if (count($plan['allow']) > $limit || count($plan['block']) > $limit) {
            $message = "{$plan['filter']['name']} would hold ".number_format(max(count($plan['allow']), count($plan['block'])))
                .' entries in one list, over the limit of '.number_format($limit).'. Use a smaller blocklist, split it across a layer, or raise the limit under Settings → Network provider (UniFi handles up to about 100,000).';
            $this->audit->log('policy.controller_push_refused', $policy, ['reason' => 'size', 'allow' => count($plan['allow']), 'block' => count($plan['block'])]);

            return ['ok' => false, 'message' => $message];
        }
        $changeCount = collect($plan['changes'])->sum(fn (array $values) => count($values));
        if ($trigger !== 'manual' && $changeCount > $this->maxAutomaticChanges()) {
            $this->audit->log('policy.controller_push_refused', $policy, ['reason' => 'automatic_change_limit', 'changes' => $changeCount]);

            return ['ok' => false, 'message' => "{$policy->name}: ".number_format($changeCount).' changes are waiting. Automatic pushes stop at '.number_format($this->maxAutomaticChanges()).' changes; review them and push from the policy page.'];
        }

        // A push that would empty a filter, or remove most of it, is almost never intended: only a
        // person pushing this policy on its own (who has seen the plan) can send it.
        $before = count($plan['filter']['allow']) + count($plan['filter']['block']);
        $removing = count($plan['changes']['allow_remove']) + count($plan['changes']['block_remove']);
        if (! $reviewed && $removing >= 3 && $removing * 2 >= $before) {
            $this->audit->log('policy.controller_push_refused', $policy, ['reason' => 'mass_removal', 'removing' => $removing, 'before' => $before, 'trigger' => $trigger]);

            return ['ok' => false, 'held' => true, 'message' => "{$policy->name}: this push would remove ".number_format($removing).' of '.number_format($before)." entries in {$plan['filter']['name']}. Nothing was sent; check the plan and push it from the policy page if it is right."];
        }

        $record = [
            'policy_release_id' => $this->publisher()->current()?->id,
            'filter_policy_id' => $policy->id, 'user_id' => $actor?->id, 'provider_reference' => $plan['filter']['id'],
            'provider_name' => $plan['filter']['name'], 'trigger' => $trigger,
            'before_allow' => $plan['filter']['allow'], 'before_block' => $plan['filter']['block'],
            'after_allow' => $plan['allow'], 'after_block' => $plan['block'], 'changes' => $plan['changes'],
        ];

        try {
            $this->writer()?->writeContentFilterLists($plan['filter']['id'], $plan['allow'], $plan['block']);
        } catch (ProviderRequestFailed $exception) {
            ControllerPush::query()->create($record + ['status' => 'failed', 'message' => $exception->getMessage()]);
            $this->audit->log('policy.controller_push_failed', $policy, ['message' => $exception->getMessage(), 'trigger' => $trigger]);

            return ['ok' => false, 'message' => "{$policy->name}: {$exception->getMessage()}"];
        } catch (Throwable $exception) {
            report($exception);
            ControllerPush::query()->create($record + ['status' => 'failed', 'message' => 'The controller could not be reached.']);

            return ['ok' => false, 'message' => "{$policy->name}: the controller could not be reached. Nothing was changed."];
        }

        $message = "{$plan['filter']['name']}: {$plan['message']}";
        $push = ControllerPush::query()->create($record + ['status' => 'ok', 'message' => $message]);
        if (filled($policy->push_hold)) {
            $this->audit->log('policy.push_hold_cleared', $policy, ['hold' => $policy->push_hold, 'push' => $push->id]);
            $policy->forceFill(['push_hold' => null])->save();
        }
        $managed = $policy->managed();
        $policy->forceFill(['controller_managed' => [
            'allow' => array_values(array_diff(array_unique([...$managed['allow'], ...$plan['changes']['allow_add']]), $plan['changes']['allow_remove'])),
            'block' => array_values(array_diff(array_unique([...$managed['block'], ...$plan['changes']['block_add']]), $plan['changes']['block_remove'])),
        ]])->save();
        $this->sync->forget();
        $this->sync->pull($policy, $actor);
        $this->checker()->setBaseline($policy, $plan['allow'], $plan['block']);
        $this->checker()->checkOne($policy->refresh(), refresh: false);
        $this->audit->log('policy.controller_pushed', $policy, ['push_id' => $push->id, 'trigger' => $trigger, 'changes' => $plan['changes']]);
        app(RequestWorkflow::class)->settlePushed($actor);

        return ['ok' => true, 'message' => $message, 'push' => $push];
    }

    private function checker(): SyncChecker
    {
        return app(SyncChecker::class);
    }

    /**
     * Rebuild a raw controller list: keep untouched entries exactly as the
     * controller had them, drop managed removals, append additions.
     *
     * @param  list<string>  $current
     * @param  list<string>  $add
     * @param  list<string>  $remove
     * @return list<string>
     */
    private function apply(array $current, array $add, array $remove): array
    {
        $kept = [];
        $present = [];
        $removing = array_flip($remove);
        foreach ($current as $raw) {
            $normalised = $this->parser->normalise($raw);
            if (isset($removing[$normalised]) || isset($present[$normalised])) {
                continue;
            }
            $present[$normalised] = true;
            $kept[] = $raw;
        }
        foreach ($add as $domain) {
            if (! isset($present[$domain])) {
                $present[$domain] = true;
                $kept[] = $domain;
            }
        }

        return $kept;
    }

    /** @param array{allow_add: list<string>, allow_remove: list<string>, block_add: list<string>, block_remove: list<string>} $changes */
    private function describe(array $changes): string
    {
        $parts = [];
        foreach (['allow_add' => 'allow +', 'allow_remove' => 'allow −', 'block_add' => 'block +', 'block_remove' => 'block −'] as $key => $label) {
            if ($changes[$key]) {
                $parts[] = $label.count($changes[$key]);
            }
        }

        return $parts ? implode(', ', $parts).'.' : 'no changes.';
    }

    /** @param list<string> $a @param list<string> $b */
    private function sameLists(array $a, array $b): bool
    {
        $normalise = fn (array $values) => collect($values)->map(fn ($value) => strtolower((string) $value))->unique()->sort()->values()->all();

        return $normalise($a) === $normalise($b);
    }
}
