<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A content-filter profile in the network provider, such as the UniFi
 * "Students" content filter applied to the Student VLAN.
 */
class FilterPolicy extends Model
{
    protected $fillable = [
        'name', 'slug', 'audience', 'layer_of_id', 'description', 'network_profile_id', 'provider_reference', 'provider_name',
        'enabled', 'sort_order', 'current_allowlist', 'current_blocklist', 'snapshot_at', 'snapshot_by_id',
        'sync_status', 'sync_checked_at', 'sync_details', 'sync_baseline', 'controller_exclusions', 'controller_managed',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'current_allowlist' => 'array',
            'current_blocklist' => 'array',
            'snapshot_at' => 'datetime',
            'sync_checked_at' => 'datetime',
            'sync_details' => 'array',
            'sync_baseline' => 'array',
            'controller_exclusions' => 'array',
            'controller_managed' => 'array',
        ];
    }

    /** The network type this belongs to (its shared allow and block lists). */
    public function networkType(): BelongsTo
    {
        return $this->belongsTo(NetworkType::class, 'audience', 'key');
    }

    public function typeLabel(): string
    {
        return NetworkType::labelFor($this->audience);
    }

    public function networkProfile(): BelongsTo
    {
        return $this->belongsTo(NetworkProfile::class);
    }

    public function snapshotBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'snapshot_by_id');
    }

    /**
     * Hostnames removed from the controller filter by hand, per list kind.
     *
     * @return array{allow: list<string>, block: list<string>}
     */
    public function exclusions(): array
    {
        return ['allow' => array_values($this->controller_exclusions['allow'] ?? []), 'block' => array_values($this->controller_exclusions['block'] ?? [])];
    }

    /**
     * Hostnames this app wrote into the controller filter, per list kind.
     *
     * @return array{allow: list<string>, block: list<string>}
     */
    public function managed(): array
    {
        return ['allow' => array_values($this->controller_managed['allow'] ?? []), 'block' => array_values($this->controller_managed['block'] ?? [])];
    }

    /** The policy this layer adds blocks to. */
    public function layerOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'layer_of_id');
    }

    /** The layer filter that carries this policy's blocklist feeds. */
    public function layer(): HasOne
    {
        return $this->hasOne(self::class, 'layer_of_id');
    }

    public function isLayer(): bool
    {
        return $this->layer_of_id !== null;
    }

    /** The layer that has taken over this policy's feeds: enabled and linked to a controller filter. */
    public function activeLayer(): ?self
    {
        $layer = $this->isLayer() ? null : $this->layer;

        return $layer && $layer->enabled && filled($layer->provider_reference) ? $layer : null;
    }

    /** @param Builder<self> $query */
    public function scopeMain(Builder $query): void
    {
        $query->whereNull('layer_of_id');
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
