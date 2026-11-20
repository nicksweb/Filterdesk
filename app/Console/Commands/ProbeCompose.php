<?php

namespace App\Console\Commands;

use App\Services\Policy\ProbePlanner;
use Illuminate\Console\Command;

/**
 * Prints compose.vlan.yml for every planned probe, so it can be written on
 * the Docker host:
 * docker compose exec -T app php artisan probes:compose --parent=eth0 > compose.vlan.yml
 */
class ProbeCompose extends Command
{
    protected $signature = 'probes:compose
        {--parent=eth0 : Host interface that carries the tagged VLANs}
        {--network=* : Extra controller network id to give its own probe}';

    protected $description = 'Print compose.vlan.yml with one probe container per policy (and any extra networks)';

    public function handle(ProbePlanner $planner): int
    {
        $plans = $planner->plan((string) $this->option('parent'), array_values(array_filter((array) $this->option('network'), 'is_string')));
        if ($plans->isEmpty()) {
            $this->error('No probes to plan. Map networks to policies first (Policy lists → Guided setup).');

            return self::FAILURE;
        }

        $this->output->write($planner->composeFile($plans));

        return self::SUCCESS;
    }
}
