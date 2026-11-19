<?php

namespace App\Console\Commands;

use App\Services\Policy\SyncChecker;
use Illuminate\Console\Command;

/** Compares each linked policy with its live controller filter and records the result. */
class CheckPolicySync extends Command
{
    protected $signature = 'policy:check-sync {--quiet-alerts : Record the result without emailing IT}';

    protected $description = 'Check whether controller content filters match their linked policies';

    public function handle(SyncChecker $checker): int
    {
        $policies = $checker->checkAll(! $this->option('quiet-alerts'));
        if ($policies->isEmpty()) {
            $this->line('No linked policies to check.');

            return self::SUCCESS;
        }

        foreach ($policies as $policy) {
            $this->line(str_pad($policy->sync_status, 12).$policy->name.': '.($policy->sync_details['summary'] ?? ''));
        }

        return self::SUCCESS;
    }
}
