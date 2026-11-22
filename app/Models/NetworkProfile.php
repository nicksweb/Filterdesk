<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A network path checked by a probe (normally one per access VLAN). */
class NetworkProfile extends Model
{
    protected $fillable = ['key', 'name', 'audience', 'probe_url', 'unifi_network_id', 'unifi_policy_id', 'enabled', 'sort_order'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
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

    public function filterPolicies(): HasMany
    {
        return $this->hasMany(FilterPolicy::class);
    }
}
