<?php

namespace App\Console\Commands;

use App\Models\FirewallList;
use App\Models\ListSource;
use App\Services\Policy\ControllerPusher;
use App\Services\Policy\FeedFetcher;
use App\Services\Policy\FirewallListPusher;
use Illuminate\Console\Command;

/**
 * Downloads feeds that are due, then (with automatic pushing on) updates
 * the firewall IP groups they feed. Domain feeds reach content filters
 * through the normal policy push.
 */
class RefreshBlocklists extends Command
{
    protected $signature = 'blocklists:refresh {--all : Refresh every enabled feed now}';

    protected $description = 'Download blocklist feeds and update firewall IP groups';

    public function handle(FeedFetcher $fetcher, FirewallListPusher $firewall, ControllerPusher $pusher): int
    {
        $refreshed = ListSource::query()->where('enabled', true)->get()
            ->filter(fn (ListSource $source) => $this->option('all') || $source->isDue())
            ->map(function (ListSource $source) use ($fetcher): ListSource {
                $this->line($fetcher->refresh($source)['message']);

                return $source;
            });

        if ($pusher->automatic()) {
            // Unchanged lists are skipped by the push itself, so only real changes reach the controller.
            $lists = FirewallList::query()->where('enabled', true)->whereNotNull('provider_reference')->get();
            foreach ($lists as $list) {
                $this->line($firewall->push($list, null)['message']);
            }
        }

        return self::SUCCESS;
    }
}
