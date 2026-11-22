<?php

namespace App\Console\Commands;

use App\Services\Network\ManagesIpGroups;
use App\Services\Network\NetworkProviderManager;
use App\Services\Network\ReadsContentFilters;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only check that the controller still answers every call this app
 * relies on, in the shape it expects. Run it after (or, on a test console,
 * before) a UniFi Network upgrade: content filtering uses endpoints outside
 * the official API, which a release could change.
 */
class CheckControllerCompatibility extends Command
{
    protected $signature = 'unifi:compat {--json : Print the result as JSON}';

    protected $description = 'Check (read-only) that the network controller answers every call the app uses';

    public function handle(NetworkProviderManager $providers): int
    {
        try {
            $provider = $providers->current();
        } catch (Throwable $exception) {
            $this->error('No network provider: '.$exception->getMessage());

            return self::FAILURE;
        }

        $checks = [];
        $run = function (string $name, callable $call, callable $valid) use (&$checks): void {
            try {
                $result = $call();
                $checks[] = ['check' => $name, 'ok' => (bool) $valid($result), 'detail' => is_countable($result) ? count($result).' item(s)' : 'answered'];
            } catch (Throwable $exception) {
                $checks[] = ['check' => $name, 'ok' => false, 'detail' => str($exception->getMessage())->limit(160)->toString()];
            }
        };
        $hasKeys = fn (array $keys) => fn (array $items) => $items === [] || array_diff($keys, array_keys((array) $items[0])) === [];

        if ($provider instanceof ReadsContentFilters) {
            $run('Networks (rest/networkconf)', fn () => $provider->networks(), $hasKeys(['id', 'name', 'vlan']));
            $run('Content filters (v2 content-filtering)', fn () => $provider->contentFilters(), $hasKeys(['id', 'name', 'allow', 'block', 'categories']));
            $run('Content categories', fn () => $provider->availableCategories(), fn (array $items) => $items !== []);
        }
        if ($provider instanceof ManagesIpGroups) {
            $run('IP groups (traffic-matching-lists)', fn () => $provider->ipGroups(), $hasKeys(['id', 'name', 'family', 'count']));
            $run('Firewall zones', fn () => $provider->firewallZones(), fn (array $items) => collect($items)->contains(fn (array $zone) => strtolower($zone['name']) === 'external'));
            $run('Firewall rules (policies)', fn () => $provider->firewallRules(), $hasKeys(['id', 'name', 'action', 'index', 'source', 'destination']));
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($checks, JSON_PRETTY_PRINT));
        } else {
            $this->table(['Check', 'Result', 'Detail'], array_map(fn (array $check) => [$check['check'], $check['ok'] ? 'OK' : 'FAILED', $check['detail']], $checks));
        }

        return collect($checks)->every('ok') ? self::SUCCESS : self::FAILURE;
    }
}
