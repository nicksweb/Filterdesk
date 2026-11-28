<?php

namespace App\Services\Policy;

use App\Models\ConfigSnapshot;
use App\Models\FirewallList;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Versions of what was in force, so an administrator can put things back
 * to how they were at a date and time:
 *
 * - every content filter's allow and block lists, as read from UniFi on
 *   each sync check (every 15 minutes) and as written by each push;
 * - every firewall allow list's entries, whenever they change.
 *
 * A version is stored only when something changed. Imported IP block lists
 * (feeds) are not versioned: they are rebuilt from their sources.
 */
class StateHistory
{
    /** Versions older than this are removed, except the newest one of each. */
    public const KEEP_DAYS = 400;

    /** @param array{id: string, name: string, allow: list<string>, block: list<string>} $filter */
    public function recordFilter(array $filter, string $source): void
    {
        $allow = $this->sorted($filter['allow'] ?? []);
        $block = $this->sorted($filter['block'] ?? []);
        $this->record(ConfigSnapshot::CONTENT_FILTER, (string) $filter['id'], (string) ($filter['name'] ?? $filter['id']), ['allow' => $allow, 'block' => $block], hash('sha256', json_encode([$allow, $block])), $source);
    }

    /** @param list<array<string, mixed>> $filters */
    public function recordFilters(array $filters, string $source): void
    {
        foreach ($filters as $filter) {
            if (isset($filter['id'])) {
                rescue(fn () => $this->recordFilter($filter, $source), null, true);
            }
        }
    }

    public function recordAllowList(FirewallList $list, string $source): void
    {
        $entries = array_values($list->allow_entries ?? []);
        // What decides what gets through: the values, their addresses and when they end. Not when they were last resolved.
        $key = collect($entries)->map(fn (array $entry) => [$entry['value'] ?? '', collect(array_keys($entry['addresses'] ?? []))->sort()->values()->all(), $entry['expires_at'] ?? null])
            ->sortBy(fn (array $row) => $row[0])->values()->all();
        $this->record(ConfigSnapshot::FIREWALL_ALLOW, (string) $list->id, $list->name, ['entries' => $entries], hash('sha256', json_encode($key)), $source);
    }

    /**
     * How each content filter and allow list was at that moment: the last version of each at or before it.
     *
     * @return Collection<int, ConfigSnapshot>
     */
    public function at(CarbonInterface $when): Collection
    {
        $latest = ConfigSnapshot::query()->where('taken_at', '<=', $when)
            ->selectRaw('max(id) as id')->groupBy('kind', 'reference')->pluck('id');

        return ConfigSnapshot::query()->whereKey($latest)->orderBy('kind')->orderBy('name')->get();
    }

    /** The newest version of each. @return Collection<string, ConfigSnapshot> keyed "kind:reference" */
    public function latest(): Collection
    {
        $latest = ConfigSnapshot::query()->selectRaw('max(id) as id')->groupBy('kind', 'reference')->pluck('id');

        return ConfigSnapshot::query()->whereKey($latest)->get()->keyBy(fn (ConfigSnapshot $snapshot) => "{$snapshot->kind}:{$snapshot->reference}");
    }

    /** When history starts. */
    public function since(): ?CarbonInterface
    {
        return ConfigSnapshot::query()->min('taken_at') ? ConfigSnapshot::query()->orderBy('taken_at')->value('taken_at') : null;
    }

    /**
     * What restoring a version would change, against the newest version.
     *
     * @return array<string, list<string>> e.g. allow_add, block_remove, entries_add
     */
    public static function difference(ConfigSnapshot $then, ?ConfigSnapshot $now): array
    {
        if ($then->kind === ConfigSnapshot::FIREWALL_ALLOW) {
            $values = fn (?ConfigSnapshot $snapshot) => collect($snapshot?->data['entries'] ?? [])->pluck('value')->unique()->values()->all();

            return ['entries_add' => array_values(array_diff($values($then), $values($now))), 'entries_remove' => array_values(array_diff($values($now), $values($then)))];
        }

        return [
            'allow_add' => array_values(array_diff($then->data['allow'] ?? [], $now?->data['allow'] ?? [])),
            'allow_remove' => array_values(array_diff($now?->data['allow'] ?? [], $then->data['allow'] ?? [])),
            'block_add' => array_values(array_diff($then->data['block'] ?? [], $now?->data['block'] ?? [])),
            'block_remove' => array_values(array_diff($now?->data['block'] ?? [], $then->data['block'] ?? [])),
        ];
    }

    /**
     * Put the chosen ones back as they were.
     *
     * @param  Collection<int, ConfigSnapshot>  $snapshots
     * @return list<array{ok: bool, message: string}>
     */
    public function restore(Collection $snapshots, ?User $actor, string $when): array
    {
        $results = [];
        foreach ($snapshots as $snapshot) {
            try {
                $results[] = $snapshot->kind === ConfigSnapshot::CONTENT_FILTER
                    ? app(ControllerPusher::class)->restoreLists($snapshot->reference, $snapshot->data['allow'] ?? [], $snapshot->data['block'] ?? [], $actor, $when)
                    : $this->restoreAllowList($snapshot, $actor, $when);
            } catch (Throwable $exception) {
                report($exception);
                $results[] = ['ok' => false, 'message' => "{$snapshot->name}: {$exception->getMessage()}"];
            }
        }

        return $results;
    }

    public function prune(): int
    {
        $keep = ConfigSnapshot::query()->selectRaw('max(id) as id')->groupBy('kind', 'reference')->pluck('id');

        return ConfigSnapshot::query()->where('taken_at', '<', now()->subDays(self::KEEP_DAYS))->whereKeyNot($keep)->delete();
    }

    /** @return array{ok: bool, message: string} */
    private function restoreAllowList(ConfigSnapshot $snapshot, ?User $actor, string $when): array
    {
        $list = FirewallList::query()->where('kind', 'allow')->find($snapshot->reference);
        if (! $list) {
            return ['ok' => false, 'message' => "{$snapshot->name}: that allow list has been deleted."];
        }
        $list->forceFill(['allow_entries' => $snapshot->data['entries'] ?? []])->save();
        $this->recordAllowList($list, 'restore');
        app(AuditLogger::class)->log('firewall.allow_list_restored', $list, ['at' => $when, 'entries' => count($snapshot->data['entries'] ?? [])]);
        $push = app(FirewallListPusher::class)->push($list->refresh(), $actor);

        return ['ok' => (bool) $push['ok'], 'message' => "{$list->name}: entries restored. ".$push['message']];
    }

    /** @param array<string, mixed> $data */
    private function record(string $kind, string $reference, string $name, array $data, string $hash, string $source): void
    {
        $last = ConfigSnapshot::query()->where('kind', $kind)->where('reference', $reference)->orderByDesc('id')->first(['id', 'hash', 'name']);
        if ($last && $last->hash === $hash) {
            return;
        }
        ConfigSnapshot::query()->create(['kind' => $kind, 'reference' => $reference, 'name' => $name, 'data' => $data, 'hash' => $hash, 'source' => $source, 'taken_at' => now()]);
    }

    /** @param list<string> $values @return list<string> */
    private function sorted(array $values): array
    {
        $values = array_values(array_unique(array_map('strval', $values)));
        sort($values);

        return $values;
    }
}
