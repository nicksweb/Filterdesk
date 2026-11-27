<?php

namespace App\Services\Network;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Policy\FirewallListPusher;
use App\Services\SettingsRepository;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * How large a UniFi IP list (traffic matching list) this gateway takes. For
 * each size: create a temporary list, read it back, rewrite 10% of it, then
 * delete it. The addresses come from 100.64.0.0/10 (shared address space,
 * never on the internet) and no firewall rule uses the list, so nothing is
 * blocked. The last results are kept for Settings → Network provider.
 */
class IpListSizeTester
{
    public const RESULTS_KEY = 'firewall.ip_list_size_test';

    public const STATUS_KEY = 'firewall.ip_list_size_test.status';

    /** @var list<int> the sizes offered */
    public const SIZES = [20000, 50000, 100000, 150000];

    public function __construct(private readonly FirewallListPusher $pusher, private readonly SettingsRepository $settings, private readonly AuditLogger $audit) {}

    /**
     * @param  list<int>  $sizes
     * @return list<array{size: int, ok: bool, create: ?float, read: ?float, count: ?int, rewrite: ?float, message: string}>
     */
    public function run(array $sizes, bool $subnets, ?User $actor = null): array
    {
        $manager = $this->pusher->manager();
        if (! $manager || ! $this->pusher->canWrite()) {
            throw new ProviderRequestFailed('Set the network provider to Active first (Settings → Network provider).');
        }
        $sizes = array_values(array_filter(array_map('intval', $sizes), fn (int $size) => $size > 0 && $size <= 250000));
        sort($sizes);

        $rows = [];
        foreach ($sizes as $position => $size) {
            Cache::put(self::STATUS_KEY, ['state' => 'running', 'since' => now()->toIso8601String(), 'step' => 'Trying '.number_format($size).' entries ('.($position + 1).' of '.count($sizes).')'], 1800);
            $row = ['size' => $size, 'ok' => false, 'create' => null, 'read' => null, 'count' => null, 'rewrite' => null, 'message' => ''];
            $name = 'Filterdesk size test '.number_format($size);
            $entries = $this->entries($size, $subnets);
            $id = null;
            try {
                $started = microtime(true);
                $id = $manager->createIpGroup($name, 'ipv4', $entries);
                $row['create'] = round(microtime(true) - $started, 1);

                $started = microtime(true);
                $row['count'] = collect($manager->ipGroups())->firstWhere('id', $id)['count'] ?? null;
                $row['read'] = round(microtime(true) - $started, 1);

                $changed = [...array_slice($entries, intdiv($size, 10)), ...$this->entries(intdiv($size, 10), $subnets, 0x200000)];
                $started = microtime(true);
                $manager->writeIpGroup($id, $name, 'ipv4', $changed);
                $row['rewrite'] = round(microtime(true) - $started, 1);

                $row['ok'] = $row['count'] === $size;
                $row['message'] = $row['ok'] ? 'Accepted' : 'Accepted, but UniFi read back '.($row['count'] === null ? 'nothing' : number_format($row['count']).' entries');
            } catch (Throwable $exception) {
                $row['message'] = 'Refused: '.str($exception->getMessage())->limit(160);
            } finally {
                if ($id) {
                    rescue(fn () => $manager->deleteIpGroup($id), null, false);
                }
            }
            $rows[] = $row;
            // A size UniFi refused: larger ones will be too.
            if (! $row['ok']) {
                break;
            }
        }

        $this->settings->set(self::RESULTS_KEY, ['at' => now()->toIso8601String(), 'by' => $actor?->name, 'subnets' => $subnets, 'rows' => $rows]);
        $this->audit->log('firewall.ip_list_size_tested', null, ['subnets' => $subnets, 'rows' => $rows, 'by' => $actor?->id]);
        Cache::put(self::STATUS_KEY, ['state' => 'done', 'since' => now()->toIso8601String()], 1800);

        return $rows;
    }

    /** @return array{at: string, by: ?string, subnets: bool, rows: list<array<string, mixed>>}|null */
    public function last(): ?array
    {
        $results = $this->settings->get(self::RESULTS_KEY);

        return is_array($results) ? $results : null;
    }

    /** The largest size the last test showed working, capped at what the setting allows. */
    public function largestAccepted(): ?int
    {
        $sizes = collect($this->last()['rows'] ?? [])->where('ok', true)->pluck('size');

        return $sizes->isEmpty() ? null : min((int) $sizes->max(), FirewallListPusher::MAX_GROUP_LIMIT);
    }

    /** @return list<string> distinct addresses (or /30s) in 100.64.0.0/10 */
    private function entries(int $count, bool $subnets, int $offset = 0): array
    {
        $base = ip2long('100.64.0.0') + $offset;
        $step = $subnets ? 4 : 1;

        return array_map(fn (int $index) => long2ip($base + $index * $step).($subnets ? '/30' : ''), range(0, $count - 1));
    }
}
