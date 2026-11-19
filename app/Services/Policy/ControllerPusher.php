<?php

namespace App\Services\Policy;

use App\Enums\AccessScope;
use App\Models\ControllerPush;
use App\Models\FilterPolicy;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Network\NetworkProviderManager;
use App\Services\Network\ProviderRequestFailed;
use App\Services\Network\WritesContentFilters;
use App\Services\Requests\RequestWorkflow;
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

        $pulled = $this->sync->pull($policy, $actor, $refresh);
        if (! $pulled['ok']) {
            return ['ok' => false, 'message' => $pulled['message']];
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
    public function push(FilterPolicy $policy, ?User $actor, string $trigger = 'manual'): array
    {
        if (! $this->enabled()) {
            return ['ok' => false, 'message' => 'Pushing is off. An admin can turn it on under Settings → Network provider (Mode: Active).'];
        }

        try {
            return Cache::lock('controller-push:'.$policy->provider_reference, 60)->block(30, fn () => $this->pushLocked($policy, $actor, $trigger));
        } catch (LockTimeoutException) {
            return ['ok' => false, 'message' => "Another push to {$policy->name} is still running. Try again in a minute."];
        }
    }

    /**
     * Push every linked policy, or only those for a scope.
     *
     * @return Collection<int, array{policy: FilterPolicy, ok: bool, message: string}>
     */
    public function pushAll(?User $actor, string $trigger = 'manual', ?AccessScope $scope = null): Collection
    {
        $this->sync->forget();
        $policies = ($scope ? $this->engine->policiesFor($scope) : FilterPolicy::query()->where('enabled', true)->ordered()->get())
            ->filter(fn (FilterPolicy $policy) => filled($policy->provider_reference));

        return $policies->map(fn (FilterPolicy $policy) => ['policy' => $policy] + $this->push($policy, $actor, $trigger))->values();
    }

    /**
     * Put a filter back to the lists it had before a push, provided nobody
     * has changed it since.
     *
     * @return array{ok: bool, message: string}
     */
    public function rollback(ControllerPush $push, ?User $actor): array
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

        ControllerPush::query()->create([
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

    /** @return array{ok: bool, message: string, push?: ControllerPush} */
    private function pushLocked(FilterPolicy $policy, ?User $actor, string $trigger): array
    {
        $plan = $this->plan($policy, $actor);
        if (! $plan['ok']) {
            return $plan;
        }

        if (collect($plan['changes'])->flatten()->isEmpty()) {
            $this->checker()->checkOne($policy->refresh(), refresh: false);
            app(RequestWorkflow::class)->settlePushed($actor);

            return ['ok' => true, 'message' => "{$plan['filter']['name']} already matches {$policy->name}."];
        }

        $record = [
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
        foreach ($current as $raw) {
            $normalised = $this->parser->normalise($raw);
            if (in_array($normalised, $remove, true) || isset($present[$normalised])) {
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
