<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Network\IpListSizeTester;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** The UniFi IP list size test, started from Settings → Network provider. One at a time. */
class RunIpListSizeTest implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 1500;

    public int $uniqueFor = 1800;

    /** @param list<int> $sizes */
    public function __construct(public readonly array $sizes, public readonly bool $sequential, public readonly ?int $actorId) {}

    public function handle(IpListSizeTester $tester): void
    {
        try {
            $tester->run($this->sizes, $this->sequential, $this->actorId ? User::query()->find($this->actorId) : null);
        } catch (Throwable $exception) {
            Cache::put(IpListSizeTester::STATUS_KEY, ['state' => 'failed', 'since' => now()->toIso8601String(), 'message' => str($exception->getMessage())->limit(200)->toString()], 1800);
        }
    }
}
