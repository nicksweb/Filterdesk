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
    protected $fillable = ['provider_network_id', 'name', 'vlan', 'purpose', 'subnet', 'dhcp_start', 'dhcp_stop', 'dhcp_mode', 'filter_policy_id', 'unfiltered_reason', 'unfiltered_reviewed_at', 'unfiltered_reviewed_by_id'];

    protected function casts(): array
    {
        return ['vlan' => 'integer', 'unfiltered_reviewed_at' => 'datetime'];
    }

    public function filterPolicy(): BelongsTo
    {
        return $this->belongsTo(FilterPolicy::class);
    }

    public function unfilteredReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unfiltered_reviewed_by_id');
    }

    /** Allowed to stay without a content filter (an admin gave a reason). */
    public function allowedUnfiltered(): bool
    {
        return filled($this->unfiltered_reason) && $this->unfiltered_reviewed_at !== null;
    }

    /** Found unfiltered when baseline filtering started; an admin has to decide. */
    public function needsBaselineDecision(): bool
    {
        return filled($this->unfiltered_reason) && $this->unfiltered_reviewed_at === null;
    }
}
