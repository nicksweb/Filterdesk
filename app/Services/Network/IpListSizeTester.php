<?php

namespace App\Services\Network;

use App\Models\FirewallList;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Policy\FirewallListPusher;
use App\Services\SettingsRepository;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * How large a UniFi IP list (traffic matching list) this gateway takes. For
 * each size: create a temporary list, read it back, rewrite 10% of it, then
 * delete it. No firewall rule uses the list, so nothing is blocked.
 *
 * UniFi limits a list by its stored size (about 126 KB), not its entries,
 * and sequential addresses store far more compactly than real ones. So the
 * test uses real data: the largest IP feed the school has, topped up with
 * random public-looking addresses (about 7% subnets). When UniFi refuses a
 * size, its byte figures say how many of these entries fit, and the
 * suggestion leaves a 10% margin.
 */
class IpListSizeTester
{
    public const RESULTS_KEY = 'firewall.ip_list_size_test';

    public const STATUS_KEY = 'firewall.ip_list_size_test.status';

    /** @var list<int> the sizes offered */
    public const SIZES = [20000, 30000, 50000, 100000];

    public function __construct(private readonly FirewallListPusher $pusher, private readonly SettingsRepository $settings, private readonly AuditLogger $audit) {}

    /**
     * @param  list<int>  $sizes
     * @param  bool  $sequential  the old test data: sequential 100.64.x.x addresses (stores compactly, so it overstates)
     * @return list<array{size: int, ok: bool, create: ?float, read: ?float, count: ?int, rewrite: ?float, message: string, fits?: int, bytes?: int, limit?: int}>
     */
    public function run(array $sizes, bool $sequential = false, ?User $actor = null): array
    {
        $manager = $this->pusher->manager();
        if (! $manager || ! $this->pusher->canWrite()) {
            throw new ProviderRequestFailed('Set the network provider to Active first (Settings → Network provider).');
        }
        $sizes = array_values(array_filter(array_map('intval', $sizes), fn (int $size) => $size > 0 && $size <= 250000));
        sort($sizes);
        $feed = $sequential ? [] : $this->feedEntries();
        $data = $sequential ? 'sequential 100.64.x.x addresses' : ($feed !== [] ? 'your largest IP feed, topped up with random public addresses' : 'random public addresses, about 7% subnets');

        $rows = [];
        foreach ($sizes as $position => $size) {
            Cache::put(self::STATUS_KEY, ['state' => 'running', 'since' => now()->toIso8601String(), 'step' => 'Trying '.number_format($size).' entries ('.($position + 1).' of '.count($sizes).')'], 1800);
            $row = ['size' => $size, 'ok' => false, 'create' => null, 'read' => null, 'count' => null, 'rewrite' => null, 'message' => ''];
            $name = 'Filterdesk size test '.number_format($size);
            $entries = $this->entries($size, $sequential, $feed, 1);
            $id = null;
            try {
                $started = microtime(true);
                $id = $manager->createIpGroup($name, 'ipv4', $entries);
                $row['create'] = round(microtime(true) - $started, 1);

                // Read it back from UniFi, not from the 5-minute cache of lists.
                $started = microtime(true);
                if (method_exists($manager, 'forgetFirewall')) {
                    $manager->forgetFirewall();
                }
                $row['count'] = collect($manager->ipGroups())->firstWhere('id', $id)['count'] ?? null;
                $row['read'] = round(microtime(true) - $started, 1);

                $changed = [...array_slice($entries, intdiv($size, 10)), ...$this->entries(intdiv($size, 10), $sequential, [], 2)];
                $started = microtime(true);
                $manager->writeIpGroup($id, $name, 'ipv4', array_values(array_unique($changed)));
                $row['rewrite'] = round(microtime(true) - $started, 1);

                $row['ok'] = $row['count'] === $size;
                $row['message'] = $row['ok'] ? 'Accepted' : 'Accepted, but UniFi read back '.($row['count'] === null ? 'nothing' : number_format($row['count']).' entries');
            } catch (IpListTooLarge $exception) {
                $row += ['fits' => $exception->entriesThatFit(), 'bytes' => $exception->size, 'limit' => $exception->limit];
                $row['message'] = 'Refused: '.number_format($exception->size).' bytes as UniFi stores it, over its limit of '.number_format($exception->limit).'. About '.number_format((int) floor($size * $exception->limit / max(1, $exception->size))).' of these entries fit.';
            } catch (Throwable $exception) {
                $row['message'] = 'Refused: '.str($exception->getMessage())->limit(200);
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

        $this->settings->set(self::RESULTS_KEY, ['at' => now()->toIso8601String(), 'by' => $actor?->name, 'data' => $data, 'sequential' => $sequential, 'rows' => $rows]);
        $this->audit->log('firewall.ip_list_size_tested', null, ['data' => $data, 'rows' => $rows, 'by' => $actor?->id]);
        Cache::put(self::STATUS_KEY, ['state' => 'done', 'since' => now()->toIso8601String()], 1800);

        return $rows;
    }

    /** @return array{at: string, by: ?string, data?: string, sequential?: bool, rows: list<array<string, mixed>>}|null */
    public function last(): ?array
    {
        $results = $this->settings->get(self::RESULTS_KEY);

        return is_array($results) ? $results : null;
    }

    /**
     * The size to use, with a 10% margin: from UniFi's byte figures when it
     * refused a size, else 90% of the largest size accepted. Null for a test
     * with sequential addresses, which overstates what real lists take.
     */
    public function suggested(): ?int
    {
        $last = $this->last();
        if (! $last || ($last['sequential'] ?? true)) {
            return null;
        }
        $rows = collect($last['rows'] ?? []);
        $fits = $rows->pluck('fits')->filter()->min();
        $accepted = $rows->where('ok', true)->pluck('size')->max();
        $size = $fits ? max((int) $fits, (int) $accepted) : ($accepted ? (int) floor($accepted * 0.9) : null);
        if (! $size) {
            return null;
        }

        return min(FirewallListPusher::MAX_GROUP_LIMIT, max(1000, intdiv($size, 1000) * 1000));
    }

    /** Real data: the entries of the largest enabled IPv4 block list. @return list<string> */
    private function feedEntries(): array
    {
        $largest = FirewallList::query()->where('kind', '!=', 'allow')->where('family', 'ipv4')->where('enabled', true)->get()
            ->map(fn (FirewallList $list) => $list->desiredEntries()['entries'])->sortByDesc(fn (array $entries) => count($entries))->first();

        return array_values($largest ?? []);
    }

    /**
     * @param  list<string>  $feed  real entries to use first
     * @return list<string>
     */
    private function entries(int $count, bool $sequential, array $feed, int $seed): array
    {
        if ($sequential) {
            $base = ip2long('100.64.0.0') + ($seed - 1) * 0x200000;

            return array_map(fn (int $index) => long2ip($base + $index), range(0, $count - 1));
        }
        $entries = array_slice($feed, 0, $count);
        $seen = array_flip($entries);
        // Random public-looking addresses, the same every run, about 7% of them subnets.
        mt_srand($seed * 7919 + $count);
        while (count($entries) < $count) {
            $address = mt_rand(ip2long('1.0.0.0'), ip2long('223.255.255.255'));
            if (filter_var(long2ip($address), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                continue;
            }
            $prefix = mt_rand(1, 100) <= 7 ? mt_rand(24, 29) : 32;
            $value = $prefix === 32 ? long2ip($address) : long2ip($address & (-1 << (32 - $prefix)) & 0xFFFFFFFF).'/'.$prefix;
            if (! isset($seen[$value])) {
                $seen[$value] = true;
                $entries[] = $value;
            }
        }
        mt_srand();

        return $entries;
    }
}
