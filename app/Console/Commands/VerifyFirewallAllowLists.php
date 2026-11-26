<?php

namespace App\Console\Commands;

use App\Models\FirewallList;
use App\Notifications\FilterSyncAlertNotification;
use App\Services\Policy\FirewallAllowList;
use App\Services\Requests\RequestNotifier;
use Illuminate\Console\Command;

/**
 * Nightly: each firewall allow list's domains are resolved again and
 * compared with the addresses they had when added (moved to new addresses,
 * or no longer resolving), and every entry is checked against UniFi's block
 * rules (no rule blocks it any more, so it may not be needed). New flags
 * are shown on the list's page and emailed to IT.
 */
class VerifyFirewallAllowLists extends Command
{
    protected $signature = 'firewall:verify-allow';

    protected $description = 'Check firewall allow list entries: moved, no longer resolving, or no longer needed';

    public function handle(FirewallAllowList $allow, RequestNotifier $notifier): int
    {
        $alerts = [];
        foreach (FirewallList::query()->where('kind', 'allow')->where('enabled', true)->get() as $list) {
            $flags = $allow->verify($list);
            foreach ($flags as $flag) {
                $this->line("{$list->name}: {$flag}");
            }
            if ($flags) {
                $alerts[] = ['policy' => $list->name, 'slug' => '', 'status' => 'firewall', 'summary' => implode(' ', $flags)];
            }
        }
        if ($alerts) {
            $notifier->filterSyncAlert(new FilterSyncAlertNotification($alerts));
        }

        return self::SUCCESS;
    }
}
