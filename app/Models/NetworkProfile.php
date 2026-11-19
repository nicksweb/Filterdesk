<?php

namespace App\Models;

use App\Enums\Audience;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A network path checked by a probe (normally one per access VLAN). */
class NetworkProfile extends Model
{
    protected $fillable = ['key', 'name', 'audience', 'probe_url', 'unifi_network_id', 'unifi_policy_id', 'enabled', 'sort_order'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'audience' => Audience::class];
    }

    public function filterPolicies(): HasMany
    {
        return $this->hasMany(FilterPolicy::class);
    }
}
