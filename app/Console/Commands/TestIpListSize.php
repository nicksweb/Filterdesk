<?php

namespace App\Console\Commands;

use App\Services\Network\IpListSizeTester;
use App\Services\Network\ProviderRequestFailed;
use Illuminate\Console\Command;

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

    public function handle(IpListSizeTester $tester): int
    {
        $sizes = array_values(array_filter(array_map('intval', explode(',', (string) $this->option('sizes'))), fn (int $size) => $size > 0 && $size <= 250000));
        if (! $this->option('force') && ! $this->confirm('Create (then delete) temporary UniFi IP lists of '.implode(', ', array_map('number_format', $sizes)).' entries? No firewall rule uses them.')) {
            return self::SUCCESS;
        }
        try {
            $rows = $tester->run($sizes, (bool) $this->option('subnets'));
        } catch (ProviderRequestFailed $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->table(['Entries', 'Create', 'Read back', 'Rewrite 10%', 'Result'], array_map(fn (array $row) => [
            number_format($row['size']), $row['create'] === null ? '—' : $row['create'].' s',
            $row['read'] === null ? '—' : ($row['count'] === null ? '?' : number_format($row['count'])).' in '.$row['read'].' s',
            $row['rewrite'] === null ? '—' : $row['rewrite'].' s', $row['message'],
        ], $rows));
        $this->line('The results are also on Settings → Network provider. Check the gateway\'s CPU and memory in UniFi while larger lists are in use, and that the Firewall pages still load, before raising Addresses per UniFi IP list.');

        return self::SUCCESS;
    }
}
