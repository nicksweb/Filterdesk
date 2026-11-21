<?php

namespace App\Console\Commands;

use App\Services\Policy\CategoryGuard;
use App\Services\Policy\ControllerPusher;
use Illuminate\Console\Command;

/**
 * Adds allow entries for smart-approved hostnames whose category has become
 * blocked, then pushes. Runs after every sync check; --refresh (daily) also
 * re-asks the category service about those hostnames, using its week-long cache.
 */
class GuardCategories extends Command
{
    protected $signature = 'categories:guard {--refresh : Re-check the categories of smart-approved hostnames first}';

    protected $description = 'Keep smart-approved sites allowed when their category becomes blocked';

    public function handle(CategoryGuard $guard, ControllerPusher $pusher): int
    {
        $added = $this->option('refresh') ? $guard->refresh() : $guard->check();
        foreach ($added as $line) {
            $this->line("Allowed {$line}");
        }
        if ($added !== [] && $pusher->automatic()) {
            foreach ($pusher->pushAll(null, 'category_guard') as $result) {
                $this->line(($result['ok'] ? 'OK   ' : 'FAIL ').$result['message']);
            }
        }
        if ($added === []) {
            $this->line('No smart-approved site needs an allow entry.');
        }

        return self::SUCCESS;
    }
}
