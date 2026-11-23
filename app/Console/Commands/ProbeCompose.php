<?php

namespace App\Console\Commands;

use App\Services\Policy\ProbePlanner;
use App\Services\SettingsRepository;
use Illuminate\Console\Command;

/**
 * Prints compose.vlan.yml for every planned probe, so it can be written on
 * the Docker host:
 * docker compose exec -T app php artisan probes:compose > compose.vlan.yml
 */
class ProbeCompose extends Command
{
    protected $signature = 'probes:compose
        {--parent= : Default host trunk interface (otherwise the one saved in Guided setup)}
        {--network=* : Extra controller network id to give its own probe (otherwise the saved ones)}
        {--proxy= : yes or no: include compose.proxy.yml in the COMPOSE_FILE line (otherwise as last seen in Guided setup)}';

    protected $description = 'Print compose.vlan.yml with one probe container per policy (and any extra networks)';

    public function handle(ProbePlanner $planner): int
    {
        $extra = array_values(array_filter((array) $this->option('network'), 'is_string'));
        $plans = $planner->probes($this->option('parent') ?: null, $extra === [] ? null : $extra);
        if ($plans->isEmpty()) {
            $this->error('No probes to plan. Map networks to policies first (Policies → Guided setup).');

            return self::FAILURE;
        }

        $proxy = match (strtolower((string) $this->option('proxy'))) {
            'yes', '1', 'true' => true,
            'no', '0', 'false' => false,
            default => (bool) app(SettingsRepository::class)->get('probes.behind_proxy', false),
        };
        if (! $planner->ready($plans)) {
            $this->getOutput()->getErrorStyle()->warning('Not ready: set the trunk interface in Guided setup (bin/probe-interfaces on the host finds it). The file has <...> placeholders.');
        }
        $this->output->write($planner->composeFile($plans, $proxy));

        return self::SUCCESS;
    }
}
