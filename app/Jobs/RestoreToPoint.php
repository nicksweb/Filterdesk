<?php

namespace App\Jobs;

use App\Models\ConfigSnapshot;
use App\Models\User;
use App\Services\Policy\StateHistory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Changes → Restore to a point in time, in the background. One at a time. */
class RestoreToPoint implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const STATUS_KEY = 'restore.point.status';

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 1200;

    /** @param list<int> $snapshotIds */
    public function __construct(public readonly array $snapshotIds, public readonly string $when, public readonly ?int $actorId) {}

    public function handle(StateHistory $history): void
    {
        Cache::put(self::STATUS_KEY, ['state' => 'running', 'when' => $this->when, 'since' => now()->toIso8601String(), 'results' => []], 3600);
        try {
            $snapshots = ConfigSnapshot::query()->whereKey($this->snapshotIds)->orderBy('kind')->orderBy('name')->get();
            $results = $history->restore($snapshots, $this->actorId ? User::query()->find($this->actorId) : null, $this->when);
            Cache::put(self::STATUS_KEY, ['state' => 'done', 'when' => $this->when, 'since' => now()->toIso8601String(), 'results' => $results], 86400);
        } catch (Throwable $exception) {
            report($exception);
            Cache::put(self::STATUS_KEY, ['state' => 'failed', 'when' => $this->when, 'since' => now()->toIso8601String(), 'results' => [['ok' => false, 'message' => str($exception->getMessage())->limit(200)->toString()]]], 86400);
        }
    }
}
