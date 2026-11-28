<?php

namespace App\Console\Commands;

use App\Models\FirewallList;
use App\Services\Policy\ControllerSync;
use App\Services\Policy\StateHistory;
use Illuminate\Console\Command;

/**
 * Hourly: a version of every content filter (read from UniFi) and every
 * firewall allow list, when it changed, and old versions pruned. The sync
 * check and every push record versions too; this covers filters with no
 * policy and the first run after an upgrade.
 */
class RecordStateHistory extends Command
{
    protected $signature = 'policy:history';

    protected $description = 'Record versions of content filters and firewall allow lists for point-in-time restore';

    public function handle(StateHistory $history, ControllerSync $sync): int
    {
        if ($sync->available()) {
            $state = $sync->state(true);
            if (! $state['error']) {
                $history->recordFilters($state['filters'], 'sync');
            }
        }
        FirewallList::query()->where('kind', 'allow')->get()->each(fn (FirewallList $list) => $history->recordAllowList($list, 'sync'));
        $pruned = $history->prune();
        $this->info('Versions recorded'.($pruned ? "; {$pruned} old one(s) removed" : '').'.');

        return self::SUCCESS;
    }
}
