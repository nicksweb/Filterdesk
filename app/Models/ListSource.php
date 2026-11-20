<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A third-party list fetched on a schedule: domains go into a policy list
 * owned by this source, IPs into a firewall IP list.
 */
class ListSource extends Model
{
    protected $fillable = [
        'name', 'preset', 'url', 'type', 'policy_list_id', 'firewall_list_id', 'enabled', 'refresh_hours', 'max_entries',
        'ip_entries', 'last_fetched_at', 'last_status', 'last_message', 'entry_count', 'etag', 'last_modified',
    ];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'ip_entries' => 'array', 'last_fetched_at' => 'datetime'];
    }

    public function policyList(): BelongsTo
    {
        return $this->belongsTo(PolicyList::class);
    }

    public function firewallList(): BelongsTo
    {
        return $this->belongsTo(FirewallList::class);
    }

    public function isDue(): bool
    {
        $wait = $this->last_status === 'failed' ? 1 : $this->refresh_hours;

        return $this->enabled && ($this->last_fetched_at === null || $this->last_fetched_at->lte(now()->subHours($wait)));
    }
}
