<?php

namespace App\Models;

use App\Services\Policy\StateHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * IP addresses and subnets kept in step with a controller IP group
 * (a UniFi traffic matching list). A block list's rules block them; an
 * allow list's rules let them through, above the block rules for the same
 * zones, and it holds entries with a note, request and expiry (domains
 * among them, re-resolved through the probes).
 */
class FirewallList extends Model
{
    /** Allow lists keep a version of their entries each time they change (Changes → Restore to a point in time). */
    protected static function booted(): void
    {
        static::saved(function (FirewallList $list): void {
            if ($list->kind === 'allow' && ($list->wasRecentlyCreated || $list->wasChanged('allow_entries'))) {
                rescue(fn () => app(StateHistory::class)->recordAllowList($list, 'change'), null, true);
            }
        });
    }

    protected $fillable = [
        'name', 'family', 'provider_reference', 'provider_name', 'manual_entries', 'enabled', 'max_entries',
        'last_pushed_at', 'last_push_status', 'last_push_message', 'live_count', 'referenced_by',
        'groups', 'rules', 'rule_zones', 'block_incoming', 'rules_first', 'pushed_hash',
        'kind', 'allow_entries', 'all_zones', 'feed_token', 'source_networks', 'placement', 'verified_at', 'new_parts', 'max_part_entries',
    ];

    protected function casts(): array
    {
        return ['manual_entries' => 'array', 'referenced_by' => 'array', 'enabled' => 'boolean', 'last_pushed_at' => 'datetime',
            'groups' => 'array', 'rules' => 'array', 'rule_zones' => 'array', 'block_incoming' => 'boolean', 'rules_first' => 'boolean',
            'allow_entries' => 'array', 'all_zones' => 'boolean', 'source_networks' => 'array', 'verified_at' => 'datetime', 'new_parts' => 'array'];
    }

    public function sources(): HasMany
    {
        return $this->hasMany(ListSource::class);
    }

    /**
     * Every address this list should contain: manual entries plus enabled
     * feeds, de-duplicated and capped at max_entries.
     *
     * @return array{entries: list<string>, total: int}
     */
    public function isAllow(): bool
    {
        return $this->kind === 'allow';
    }

    /**
     * Allow entries still in force.
     *
     * @return list<array<string, mixed>>
     */
    public function activeAllowEntries(): array
    {
        return array_values(array_filter($this->allow_entries ?? [], fn (array $entry) => empty($entry['expires_at']) || now()->lt($entry['expires_at'])));
    }

    public function desiredEntries(): array
    {
        $entries = array_flip($this->manual_entries ?? []);
        foreach ($this->isAllow() ? $this->activeAllowEntries() : [] as $entry) {
            $values = ($entry['type'] ?? null) === 'domain' ? array_keys($entry['addresses'] ?? []) : [$entry['value']];
            foreach ($values as $value) {
                if (($this->family === 'ipv6') === str_contains((string) $value, ':')) {
                    $entries[$value] = true;
                }
            }
        }
        foreach ($this->sources()->where('enabled', true)->get(['id', 'ip_entries']) as $source) {
            foreach ($source->ip_entries ?? [] as $value) {
                $entries[$value] = true;
            }
        }
        $entries = array_keys($entries);
        sort($entries);

        return ['entries' => array_slice($entries, 0, $this->max_entries), 'total' => count($entries)];
    }
}
