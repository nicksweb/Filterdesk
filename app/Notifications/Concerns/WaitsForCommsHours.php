<?php

namespace App\Notifications\Concerns;

use App\Services\Notifications\CommsHours;
use Carbon\CarbonInterface;

/** A queued email that waits for the hours emails are sent (Settings → Email and notifications). */
trait WaitsForCommsHours
{
    public function withDelay(object $notifiable, string $channel): ?CarbonInterface
    {
        return $channel === 'mail' ? app(CommsHours::class)->delayUntil() : null;
    }
}
