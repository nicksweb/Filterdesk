<?php

namespace App\Services\Policy;

use App\Enums\EntrySource;
use App\Models\ListSource;
use App\Models\PolicyEntry;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Downloads a feed and replaces its entries. The download is streamed and
 * stops at the feed's entry limit, so very large lists cannot exhaust
 * memory. Domain entries are synced in bulk: new ones inserted, missing
 * ones deleted, unchanged ones kept (with their original dates).
 */
class FeedFetcher
{
    private const CHUNK = 1000;

    public function __construct(
        private readonly FeedParser $parser,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{ok: bool, message: string} */
    public function refresh(ListSource $source, bool $force = false): array
    {
        try {
            $request = Http::withOptions(['stream' => true])->connectTimeout(10)->timeout(120)
                ->withHeaders(['User-Agent' => 'IT-Requests-blocklist/1.0'])
                ->when(! $force && $source->etag, fn ($request) => $request->withHeaders(['If-None-Match' => $source->etag]))
                ->when(! $force && $source->last_modified, fn ($request) => $request->withHeaders(['If-Modified-Since' => $source->last_modified]));
            $response = $request->get($source->url);
        } catch (Throwable $exception) {
            return $this->record($source, 'failed', 'The list could not be downloaded: '.str($exception->getMessage())->limit(160));
        }

        if ($response->status() === 304) {
            $source->forceFill(['last_fetched_at' => now(), 'last_status' => 'ok', 'last_message' => 'Unchanged since the last download.'])->save();

            return ['ok' => true, 'message' => "{$source->name} is unchanged."];
        }
        if (! $response->successful()) {
            return $this->record($source, 'failed', "The server answered HTTP {$response->status()}.");
        }

        $family = $source->firewallList?->family ?? 'ipv4';
        $values = [];
        $truncated = false;
        // IP feeds are read whole (up to max_ip_lines) and processed to fit; domain feeds stop at their limit.
        $readLimit = $source->type === 'ips' ? max((int) $source->max_entries, (int) config('blocklists.max_ip_lines', 250000)) : (int) $source->max_entries;
        $body = $response->toPsrResponse()->getBody();
        $buffer = '';
        while (! $body->eof()) {
            $buffer .= $body->read(65536);
            $lines = explode("\n", $buffer);
            $buffer = (string) array_pop($lines);
            foreach ($lines as $line) {
                $value = $source->type === 'ips' ? $this->parser->ip($line, $family) : $this->parser->domain($line);
                if ($value !== null) {
                    $values[$value] = true;
                    if (count($values) >= $readLimit) {
                        $truncated = true;
                        break 2;
                    }
                }
            }
        }
        if (! $truncated && $buffer !== '') {
            $value = $source->type === 'ips' ? $this->parser->ip($buffer, $family) : $this->parser->domain($buffer);
            if ($value !== null) {
                $values[$value] = true;
            }
        }
        $body->close();

        if ($values === []) {
            return $this->record($source, 'failed', 'The download contained no usable '.($source->type === 'ips' ? 'IP addresses' : 'domains').'. Existing entries were kept.');
        }

        $values = array_keys($values);
        $stats = null;
        if ($source->type === 'ips') {
            ['entries' => $values, 'stats' => $stats] = app(IpFeedProcessor::class)->process($values, $family, (int) $source->max_entries, (array) ($source->options ?? []));
            $truncated = $truncated || $stats['cut'] > 0;
            if ($values === []) {
                return $this->record($source, 'failed', 'Nothing was left after processing ('.IpFeedProcessor::describe($stats).') Existing entries were kept.');
            }
        }
        [$added, $removed] = $source->type === 'ips' ? $this->syncIps($source, $values) : $this->syncDomains($source, $values);

        $source->forceFill([
            'etag' => $response->header('ETag') ?: null,
            'last_modified' => $response->header('Last-Modified') ?: null,
            'entry_count' => count($values),
            'stats' => $stats,
        ])->save();
        $message = number_format(count($values)).' entries (+'.number_format($added).' / −'.number_format($removed).').'
            .($stats ? ' '.IpFeedProcessor::describe($stats) : ($truncated ? ' Stopped at the limit of '.number_format($source->max_entries).'; the rest of the list was ignored.' : ''));
        $this->audit->log('policy.feed_refreshed', $source, ['count' => count($values), 'added' => $added, 'removed' => $removed, 'truncated' => $truncated]);

        return $this->record($source, $truncated ? 'truncated' : 'ok', $message);
    }

    /**
     * @param  list<string>  $values
     * @return array{0: int, 1: int}
     */
    private function syncDomains(ListSource $source, array $values): array
    {
        $listId = $source->policy_list_id;
        $wanted = array_flip($values);
        $existing = PolicyEntry::query()->where('policy_list_id', $listId)->pluck('domain')->all();
        $existingSet = array_flip($existing);
        $remove = array_values(array_filter($existing, fn (string $domain) => ! isset($wanted[$domain])));
        $add = array_values(array_filter($values, fn (string $domain) => ! isset($existingSet[$domain])));

        DB::transaction(function () use ($listId, $remove, $add, $source): void {
            foreach (array_chunk($remove, self::CHUNK) as $chunk) {
                PolicyEntry::query()->where('policy_list_id', $listId)->whereIn('domain', $chunk)->delete();
            }
            $now = now();
            foreach (array_chunk($add, self::CHUNK) as $chunk) {
                PolicyEntry::query()->insertOrIgnore(array_map(fn (string $domain) => [
                    'policy_list_id' => $listId, 'domain' => $domain, 'source' => EntrySource::Feed->value,
                    'list_source_id' => $source->id, 'note' => null, 'created_at' => $now, 'updated_at' => $now,
                ], $chunk));
            }
        });

        return [count($add), count($remove)];
    }

    /**
     * @param  list<string>  $values
     * @return array{0: int, 1: int}
     */
    private function syncIps(ListSource $source, array $values): array
    {
        $before = array_flip($source->ip_entries ?? []);
        $after = array_flip($values);
        $source->forceFill(['ip_entries' => $values])->save();

        return [count(array_diff_key($after, $before)), count(array_diff_key($before, $after))];
    }

    /** @return array{ok: bool, message: string} */
    private function record(ListSource $source, string $status, string $message): array
    {
        $source->forceFill(['last_fetched_at' => now(), 'last_status' => $status, 'last_message' => $message])->save();

        return ['ok' => $status !== 'failed', 'message' => "{$source->name}: {$message}"];
    }
}
