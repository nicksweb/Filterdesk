<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A controller network (VLAN) and the filter policy that should apply to it.
 * The policy's audience decides whether the VLAN gets staff, student or
 * guest level filtering.
 */
class NetworkAssignment extends Model
{
    protected $fillable = ['provider_network_id', 'name', 'vlan', 'purpose', 'subnet', 'filter_policy_id'];

    protected function casts(): array
    {
        return ['vlan' => 'integer'];
    }

    public function filterPolicy(): BelongsTo
    {
        return $this->belongsTo(FilterPolicy::class);
    }
}
