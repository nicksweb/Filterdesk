<?php

namespace App\Jobs;

use App\Models\FilterPolicy;
use App\Models\FirewallList;
use App\Models\PolicyRelease;
use App\Models\User;
use App\Services\Policy\ControllerPusher;
use App\Services\Policy\FirewallListPusher;
use App\Services\Policy\PolicyPublisher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;

/**
 * A push started from a button, run in the background. Through the cloud
 * connector a push can take a minute; the page shows "in progress" and the
 * result lands in the push history (or the list's status) when it finishes.
 */
class RunManualPush implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 280;

    public int $uniqueFor = 300;

    /** @param 'policy'|'all'|'firewall'|'release'|'rollback' $kind */
    public function __construct(public readonly string $kind, public readonly ?int $targetId, public readonly ?int $actorId, public readonly bool $force = false) {}

    public static function statusKey(string $kind, ?int $targetId = null): string
    {
        return "manual-push:{$kind}:".($targetId ?? 'all');
    }

    /**
     * Queue a push and remember it is running, so the page can say so.
     * Returns the result when the queue ran it straight away (sync queue).
     *
     * @return array{state: string, message?: string}|null
     */
    public static function start(string $kind, ?int $targetId, ?User $actor, bool $force = false): ?array
    {
        Cache::put(self::statusKey($kind, $targetId), ['state' => 'running', 'since' => now()->toIso8601String()], 600);
        self::dispatch($kind, $targetId, $actor?->id, $force);
        $status = Cache::get(self::statusKey($kind, $targetId));

        return ($status['state'] ?? 'running') === 'running' ? null : $status;
    }

    /** Flash the result if the push already finished, otherwise the "running in the background" note. */
    public static function flash(RedirectResponse $redirect, ?array $result, string $queued): RedirectResponse
    {
        return $result ? $redirect->with($result['state'] === 'done' ? 'success' : 'error', $result['message'] ?? '') : $redirect->with('success', $queued);
    }

    public function uniqueId(): string
    {
        return self::statusKey($this->kind, $this->targetId);
    }

    public function handle(ControllerPusher $pusher, FirewallListPusher $firewall, PolicyPublisher $publisher): void
    {
        $actor = $this->actorId ? User::query()->find($this->actorId) : null;
        $messages = match ($this->kind) {
            'release' => [$publisher->publishAll($actor)],
            'rollback' => ($release = PolicyRelease::query()->find($this->targetId)) ? [$publisher->rollback($release, $actor)] : [],
            'policy' => ($policy = FilterPolicy::query()->find($this->targetId)) ? [$pusher->push($policy, $actor, 'manual', reviewed: true)] : [],
            'all' => $pusher->pushAll($actor)->all(),
            'firewall' => ($list = FirewallList::query()->find($this->targetId)) ? [$firewall->push($list, $actor, $this->force)] : [],
            default => [],
        };
        $ok = collect($messages)->every(fn (array $result) => $result['ok']);
        Cache::put(self::statusKey($this->kind, $this->targetId), [
            'state' => $ok ? 'done' : 'failed', 'since' => now()->toIso8601String(),
            'message' => collect($messages)->pluck('message')->join(' ') ?: 'Nothing to push.',
        ], 600);
    }

    public function failed(\Throwable $exception): void
    {
        Cache::put(self::statusKey($this->kind, $this->targetId), ['state' => 'failed', 'since' => now()->toIso8601String(), 'message' => 'The push stopped: '.$exception->getMessage()], 600);
    }
}
