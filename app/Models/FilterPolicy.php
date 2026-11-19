<?php

namespace App\Models;

use App\Enums\Audience;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A content-filter profile in the network provider, such as the UniFi
 * "Students" content filter applied to the Student VLAN.
 */
class FilterPolicy extends Model
{
    protected $fillable = [
        'name', 'slug', 'audience', 'description', 'network_profile_id', 'provider_reference', 'provider_name',
        'enabled', 'sort_order', 'current_allowlist', 'current_blocklist', 'snapshot_at', 'snapshot_by_id',
        'sync_status', 'sync_checked_at', 'sync_details', 'sync_baseline',
    ];

    protected function casts(): array
    {
        return [
            'audience' => Audience::class,
            'enabled' => 'boolean',
            'current_allowlist' => 'array',
            'current_blocklist' => 'array',
            'snapshot_at' => 'datetime',
            'sync_checked_at' => 'datetime',
            'sync_details' => 'array',
            'sync_baseline' => 'array',
        ];
    }

    public function networkProfile(): BelongsTo
    {
        return $this->belongsTo(NetworkProfile::class);
    }

    public function snapshotBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'snapshot_by_id');
    }

    public function networkAssignments(): HasMany
    {
        return $this->hasMany(NetworkAssignment::class);
    }

    public function ownLists(): HasMany
    {
        return $this->hasMany(PolicyList::class);
    }

    /** @param Builder<self> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
