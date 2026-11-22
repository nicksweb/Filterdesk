<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * IP addresses and subnets kept in step with a controller IP group
 * (a UniFi traffic matching list), which a firewall rule then blocks.
 */
class FirewallList extends Model
{
    protected $fillable = [
        'name', 'family', 'provider_reference', 'provider_name', 'manual_entries', 'enabled', 'max_entries',
        'last_pushed_at', 'last_push_status', 'last_push_message', 'live_count', 'referenced_by',
        'groups', 'rules', 'rule_zones', 'block_incoming', 'rules_first', 'pushed_hash',
    ];

    protected function casts(): array
    {
        return ['manual_entries' => 'array', 'referenced_by' => 'array', 'enabled' => 'boolean', 'last_pushed_at' => 'datetime',
            'groups' => 'array', 'rules' => 'array', 'rule_zones' => 'array', 'block_incoming' => 'boolean', 'rules_first' => 'boolean'];
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
    public function desiredEntries(): array
    {
        $entries = array_flip($this->manual_entries ?? []);
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
