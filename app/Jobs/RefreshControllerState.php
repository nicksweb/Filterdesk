<?php

namespace App\Jobs;

use App\Services\Policy\ControllerSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Re-reads the controller's filters and networks in the background, so pages never wait for it. */
class RefreshControllerState implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 120;

    public function handle(ControllerSync $sync): void
    {
        $sync->state(refresh: true);
    }
}
