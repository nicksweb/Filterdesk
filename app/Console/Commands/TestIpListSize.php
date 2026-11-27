<?php

namespace App\Console\Commands;

use App\Services\Policy\FirewallListPusher;
use Illuminate\Console\Command;
use Throwable;

/**
 * How large a UniFi IP list (traffic matching list) this gateway takes:
 * creates a temporary list of each size, reads it back, rewrites 10% of it,
 * then deletes it. The addresses are from 100.64.0.0/10 (shared address
 * space, never on the internet), and no firewall rule uses the list, so
 * nothing is blocked. Needs Active mode.
 */
class TestIpListSize extends Command
{
    protected $signature = 'filterdesk:test-ip-list-size
        {--sizes=20000,50000,100000 : Comma-separated list sizes to try}
        {--subnets : Use /30 subnets instead of single addresses, to see whether UniFi counts a subnet as one entry}
        {--force : Do not ask for confirmation}';

    protected $description = 'Try creating large UniFi IP lists (no rules), and report what the gateway accepts and how long it takes';

    public function handle(FirewallListPusher $pusher): int
    {
        $manager = $pusher->manager();
        if (! $manager || ! $pusher->canWrite()) {
            $this->error('Set the network provider to Active first (Settings → Network provider).');

            return self::FAILURE;
        }
        $sizes = array_values(array_filter(array_map('intval', explode(',', (string) $this->option('sizes'))), fn (int $size) => $size > 0 && $size <= 250000));
        if (! $this->option('force') && ! $this->confirm('Create (then delete) temporary UniFi IP lists of '.implode(', ', array_map('number_format', $sizes)).' entries? No firewall rule uses them.')) {
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($sizes as $size) {
            $entries = $this->entries($size, (bool) $this->option('subnets'));
            $name = 'Filterdesk size test '.number_format($size);
            $row = ['size' => number_format($size), 'create' => '—', 'read back' => '—', 'rewrite 10%' => '—', 'result' => ''];
            $id = null;
            try {
                $started = microtime(true);
                $id = $manager->createIpGroup($name, 'ipv4', $entries);
                $row['create'] = round(microtime(true) - $started, 1).' s';

                $started = microtime(true);
                $count = collect($manager->ipGroups())->firstWhere('id', $id)['count'] ?? null;
                $row['read back'] = ($count === null ? '?' : number_format($count)).' in '.round(microtime(true) - $started, 1).' s';

                $changed = [...array_slice($entries, intdiv($size, 10)), ...$this->entries(intdiv($size, 10), (bool) $this->option('subnets'), 0x200000)];
                $started = microtime(true);
                $manager->writeIpGroup($id, $name, 'ipv4', $changed);
                $row['rewrite 10%'] = round(microtime(true) - $started, 1).' s';
                $row['result'] = $count === $size ? 'Accepted' : 'Accepted, but read back '.($count ?? '?');
            } catch (Throwable $exception) {
                $row['result'] = 'Refused: '.str($exception->getMessage())->limit(120);
            } finally {
                if ($id) {
                    rescue(fn () => $manager->deleteIpGroup($id), null, false);
                }
            }
            $rows[] = $row;
            $this->line("{$row['size']}: {$row['result']}");
        }
        $this->table(['Entries', 'Create', 'Read back', 'Rewrite 10%', 'Result'], $rows);
        $this->line('Check the gateway\'s CPU and memory in UniFi while larger lists are in use, and that the Firewall pages still load, before raising Settings → Network provider → Addresses per UniFi IP list.');

        return self::SUCCESS;
    }

    /** @return list<string> distinct addresses (or /30s) in 100.64.0.0/10 */
    private function entries(int $count, bool $subnets, int $offset = 0): array
    {
        $base = ip2long('100.64.0.0') + $offset;
        $step = $subnets ? 4 : 1;

        return array_map(fn (int $index) => long2ip($base + $index * $step).($subnets ? '/30' : ''), range(0, $count - 1));
    }
}
