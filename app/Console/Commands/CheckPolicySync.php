<?php

namespace App\Console\Commands;

use App\Services\Policy\BaselineGuard;
use App\Services\Policy\SyncChecker;
use Illuminate\Console\Command;

/**
 * Gives unfiltered networks baseline filtering, then compares each linked
 * policy with its live controller filter and records the result.
 */
class CheckPolicySync extends Command
{
    protected $signature = 'policy:check-sync {--quiet-alerts : Record the result without emailing IT}';

    protected $description = 'Check whether controller content filters match their linked policies';

    public function handle(SyncChecker $checker, BaselineGuard $baseline): int
    {
        // No network without a content filter: give any new unfiltered one the Students filter.
        $result = $baseline->enforce();
        if ($result['filtered'] !== [] || ! $result['ok']) {
            $this->line('Baseline: '.$result['message']);
        }

        $policies = $checker->checkAll(! $this->option('quiet-alerts'));
        if ($policies->isEmpty()) {
            $this->line('No linked policies to check.');

            return self::SUCCESS;
        }

        foreach ($policies as $policy) {
            $this->line(str_pad($policy->sync_status, 12).$policy->name.': '.($policy->sync_details['summary'] ?? ''));
        }

        // Category changes are picked up by the check above; keep smart approvals working.
        $this->call('categories:guard');

        return self::SUCCESS;
    }
}
