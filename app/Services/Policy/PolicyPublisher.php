<?php

namespace App\Services\Policy;

use App\Enums\RequestStatus;
use App\Models\FilterPolicy;
use App\Models\FirewallList;
use App\Models\PolicyEntry;
use App\Models\PolicyRelease;
use App\Models\User;
use App\Models\WebsiteRequest;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Plans and publishes policy versions. Every controller write (content
 * filters and firewall IP lists) happens inside a release: one numbered
 * version per publish, automatic push or schedule, which can be reviewed and
 * rolled back as a whole. A release that changed nothing is discarded.
 *
 * Nested calls join the release already open, so "publish everything" is
 * one version even though it pushes many filters.
 */
class PolicyPublisher
{
    private ?PolicyRelease $current = null;

    public function __construct(
        private readonly ControllerPusher $pusher,
        private readonly FirewallListPusher $firewall,
        private readonly AuditLogger $audit,
    ) {}

    public function current(): ?PolicyRelease
    {
        return $this->current;
    }

    /**
     * Run controller writes inside a release.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function within(string $trigger, ?User $actor, callable $work): mixed
    {
        if ($this->current) {
            return $work();
        }

        $this->current = PolicyRelease::query()->create(['trigger' => $trigger, 'user_id' => $actor?->id, 'status' => 'running']);
        try {
            return $work();
        } finally {
            $release = $this->current;
            $this->current = null;
            $this->finish($release);
        }
    }

    /** Note a firewall list push in the open release. */
    public function recordFirewall(FirewallList $list, array $result): void
    {
        if (! $this->current || ($result['skipped'] ?? false)) {
            return;
        }
        $this->current->forceFill(['firewall' => [...($this->current->firewall ?? []), [
            'list_id' => $list->id, 'name' => $list->name, 'ok' => (bool) $result['ok'], 'changed' => (bool) $result['ok'], 'message' => $result['message'],
        ]]])->save();
    }

    /**
     * Publish everything that is waiting: every linked content filter (layers
     * first) and every firewall list with controller groups.
     *
     * @return array{ok: bool, message: string, release?: PolicyRelease}
     */
    public function publishAll(?User $actor, string $trigger = 'manual'): array
    {
        $results = $this->within($trigger, $actor, function () use ($actor, $trigger): Collection {
            $results = $this->pusher->pushAll($actor, $trigger)->map(fn (array $result) => ['ok' => $result['ok'], 'message' => $result['message']]);
            FirewallList::query()->where('enabled', true)->whereNotNull('provider_reference')->orderBy('name')->each(function (FirewallList $list) use ($actor, &$results): void {
                $results->push($this->firewall->push($list, $actor));
            });

            return $results;
        });

        $release = PolicyRelease::query()->latest('id')->first();
        $failed = $results->where('ok', false);
        $published = $release && $release->number && $release->created_at->gte(now()->subMinutes(10)) ? $release : null;

        return [
            'ok' => $failed->isEmpty(),
            'message' => ($published ? "Published {$published->label()}. " : 'Nothing needed publishing. ').($failed->isNotEmpty() ? $failed->pluck('message')->join(' ') : ''),
        ] + ($published ? ['release' => $published] : []);
    }

    /**
     * What publishing now would change, without writing anything: per linked
     * content filter the entries to add and remove, and the firewall lists
     * whose addresses or rules differ from the last push.
     *
     * @return array{filters: list<array{policy: FilterPolicy, ok: bool, message: string, changes: array<string, list<string>>, count: int}>, firewall: list<array{list: FirewallList, reason: string}>, total: int}
     */
    public function plan(): array
    {
        $filters = [];
        $policies = FilterPolicy::query()->where('enabled', true)->whereNotNull('provider_reference')->ordered()->get()
            ->sortBy(fn (FilterPolicy $policy) => $policy->isLayer() ? 0 : 1)->values();
        foreach ($policies as $policy) {
            try {
                $plan = $this->pusher->plan($policy, refresh: false);
            } catch (Throwable $exception) {
                report($exception);
                $plan = ['ok' => false, 'message' => 'The controller could not be read.'];
            }
            $changes = $plan['changes'] ?? ['allow_add' => [], 'allow_remove' => [], 'block_add' => [], 'block_remove' => []];
            $filters[] = ['policy' => $policy, 'ok' => $plan['ok'], 'message' => $plan['message'], 'changes' => $changes,
                'count' => collect($changes)->sum(fn (array $values) => count($values))];
        }

        $firewall = FirewallList::query()->where('enabled', true)->whereNotNull('provider_reference')->orderBy('name')->get()
            ->map(fn (FirewallList $list) => ['list' => $list, 'reason' => $this->firewall->pendingReason($list)])
            ->filter(fn (array $item) => $item['reason'] !== null)->values()->all();

        return ['filters' => $filters, 'firewall' => $firewall, 'total' => collect($filters)->sum('count') + count($firewall)];
    }

    /**
     * Changes made but not live because a second IT officer has to confirm them.
     *
     * @return array{entries: Collection<int, PolicyEntry>, requests: Collection<int, WebsiteRequest>}
     */
    public function awaitingSecondOfficer(): array
    {
        return [
            'entries' => PolicyEntry::query()->pending()->with(['policyList', 'proposedBy'])->orderBy('proposed_at')->get(),
            'requests' => WebsiteRequest::query()->where('status', RequestStatus::AwaitingSecondApproval)->with('requester')->orderBy('updated_at')->get(),
        ];
    }

    /**
     * Put every content filter in a release back as it was before it. Pushes
     * are undone newest first; a filter changed since is left alone and
     * reported. Firewall lists are re-derived from their sources, so they are
     * not rolled back.
     *
     * @return array{ok: bool, message: string}
     */
    public function rollback(PolicyRelease $release, ?User $actor): array
    {
        if (! $release->canRollBack()) {
            return ['ok' => false, 'message' => "{$release->label()} cannot be rolled back (only a published version that has not been rolled back already)."];
        }

        $messages = $this->within('rollback', $actor, function () use ($release, $actor): array {
            $this->current->forceFill(['rollback_of_id' => $release->id])->save();
            $messages = [];
            foreach ($release->pushes->where('status', 'ok')->whereNull('rollback_of_id')->sortByDesc('id') as $push) {
                $result = $this->pusher->rollback($push, $actor);
                $messages[] = ['ok' => $result['ok'], 'message' => $result['message']];
            }

            return $messages;
        });
        $failed = collect($messages)->where('ok', false);
        $this->audit->log('policy.release_rolled_back', null, ['release' => $release->number, 'failed' => $failed->count()]);

        return [
            'ok' => $failed->isEmpty(),
            'message' => "Rolled back {$release->label()}: ".collect($messages)->pluck('message')->join(' ')
                .($release->firewall ? ' Firewall IP lists follow their sources and were not changed.' : ''),
        ];
    }

    private function finish(PolicyRelease $release): void
    {
        $release->refresh()->load('pushes');
        $written = $release->pushes->where('status', 'ok');
        $firewall = collect($release->firewall ?? []);
        if ($release->pushes->isEmpty() && $firewall->isEmpty()) {
            $release->delete();

            return;
        }

        $failed = $release->pushes->where('status', 'failed')->count() + $firewall->where('ok', false)->count();
        $succeeded = $written->count() + $firewall->where('ok', true)->count();
        $status = match (true) {
            $succeeded === 0 => 'failed',
            $failed > 0 => 'partial',
            default => 'published',
        };
        $summary = collect([
            $written->isNotEmpty() ? $written->count().' content filter(s), '.$written->sum(fn ($push) => $push->changeCount()).' change(s)' : null,
            $firewall->where('ok', true)->isNotEmpty() ? $firewall->where('ok', true)->count().' firewall list(s)' : null,
            $failed ? "{$failed} failed" : null,
        ])->filter()->join('; ');

        Cache::lock('policy-release-number', 10)->block(10, function () use ($release, $succeeded, $status, $summary): void {
            $release->forceFill([
                'status' => $status, 'summary' => $summary, 'finished_at' => now(),
                'number' => $succeeded > 0 ? ((int) PolicyRelease::query()->max('number')) + 1 : null,
            ])->save();
        });
    }
}
