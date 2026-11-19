<?php

namespace App\Console\Commands;

use App\Services\Policy\ControllerPusher;
use Illuminate\Console\Command;

/**
 * Pushes every linked policy whose controller filter is out of step, so
 * expiries, scheduled starts and list edits reach the controller without
 * anyone clicking. Does nothing unless automatic pushing is turned on.
 */
class PushPolicies extends Command
{
    protected $signature = 'policy:push {--force : Push even when automatic pushing is off (writes must still be enabled)}';

    protected $description = 'Apply pending policy changes to the linked controller content filters';

    public function handle(ControllerPusher $pusher): int
    {
        if (! $pusher->enabled() || (! $pusher->automatic() && ! $this->option('force'))) {
            $this->line('Automatic pushing is off.');

            return self::SUCCESS;
        }

        $results = $pusher->pushAll(null, 'schedule');
        foreach ($results as $result) {
            $this->line(($result['ok'] ? 'OK   ' : 'FAIL ').$result['message']);
        }

        return $results->every(fn (array $result) => $result['ok']) ? self::SUCCESS : self::FAILURE;
    }
}
