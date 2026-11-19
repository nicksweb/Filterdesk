<?php

namespace App\Models;

use App\Enums\AccessScope;
use App\Enums\RequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebsiteRequest extends Model
{
    protected $fillable = [
        'reference', 'requester_id', 'assigned_to_id', 'domains', 'access_scope',
        'category', 'use_case', 'standards_confirmed', 'status', 'requested_from',
        'requested_until', 'approved_at', 'expires_at', 'resolved_at', 'resolution',
        'provider_state', 'approved_domains', 'related_domains', 'applied_scope', 'approved_by_id', 'assigned_at',
    ];

    protected function casts(): array
    {
        return [
            'domains' => 'array',
            'provider_state' => 'array',
            'approved_domains' => 'array',
            'related_domains' => 'array',
            'applied_scope' => AccessScope::class,
            'assigned_at' => 'datetime',
            'access_scope' => AccessScope::class,
            'status' => RequestStatus::class,
            'standards_confirmed' => 'boolean',
            'requested_from' => 'datetime',
            'requested_until' => 'datetime',
            'approved_at' => 'datetime',
            'expires_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(RequestEvent::class);
    }

    public function policyEntries(): HasMany
    {
        return $this->hasMany(PolicyEntry::class);
    }

    /** @return list<string> The domains that were (or will be) placed into policy. */
    public function effectiveDomains(): array
    {
        return $this->approved_domains ?: $this->domains;
    }

    public function effectiveScope(): AccessScope
    {
        return $this->applied_scope ?? $this->access_scope;
    }

    public function isTemporary(): bool
    {
        return $this->requested_until !== null;
    }
}
