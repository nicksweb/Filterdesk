<?php

namespace App\Console\Commands;

use App\Models\NetworkProfile;
use App\Services\Website\ProbeDiagnostics;
use Illuminate\Console\Command;

/** Runs every probe's setup checklist, so the dashboard and setup pages can show what is left to do. */
class CheckProbes extends Command
{
    protected $signature = 'probes:check';

    protected $description = 'Run the setup checklist for every probe and print what to fix';

    public function handle(ProbeDiagnostics $diagnostics): int
    {
        $profiles = NetworkProfile::query()->orderBy('sort_order')->get();
        $results = $diagnostics->forAll($profiles, refresh: true);
        if ($diagnostics->unreachable) {
            $this->error("No probe name resolves from {$diagnostics->unreachable}, so this container is not on the probe network. The probes' last results are kept.");
            $this->line('      * In compose.yml, the app, queue and scheduler services need probe_control in their networks (2026.12.18 and later have it), then docker compose up -d.');

            return self::FAILURE;
        }
        foreach ($results as $id => $result) {
            $this->line(str_pad(strtoupper($result['state']), 6).$profiles->firstWhere('id', $id)->name.': '.$result['summary']);
            foreach ($result['checks'] as $check) {
                if (in_array($check['status'], ['fail', 'warn', 'todo'], true)) {
                    $this->line("      - {$check['label']}: {$check['detail']}");
                    foreach ($check['fix'] as $step) {
                        $this->line("          * {$step}");
                    }
                }
            }
        }

        return self::SUCCESS;
    }
}
