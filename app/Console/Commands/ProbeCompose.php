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
        {--parent= : Default host trunk interface (otherwise the one saved in Guided setup)}
        {--network=* : Extra controller network id to give its own probe (otherwise the saved ones)}';

    protected $description = 'Print compose.vlan.yml with one probe container per policy (and any extra networks)';

    public function handle(ProbePlanner $planner): int
    {
        $extra = array_values(array_filter((array) $this->option('network'), 'is_string'));
        $plans = $planner->plan($this->option('parent') ?: null, $extra === [] ? null : $extra);
        if ($plans->isEmpty()) {
            $this->error('No probes to plan. Map networks to policies first (Policies → Guided setup).');

            return self::FAILURE;
        }

        $this->output->write($planner->composeFile($plans));

        return self::SUCCESS;
    }
}
