<?php

namespace App\Models;

use App\Enums\AccessScope;
use App\Enums\RequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class WebsiteRequest extends Model
{
    protected $fillable = [
        'reference', 'requester_id', 'assigned_to_id', 'domains', 'access_scope',
        'category', 'use_case', 'standards_confirmed', 'status', 'requested_from',
        'requested_until', 'approved_at', 'access_from', 'expires_at', 'expiry_reminded_at', 'resolved_at', 'resolution',
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
            'access_from' => 'datetime',
            'expiry_reminded_at' => 'datetime',
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

    /** Start of access: as approved once approved, otherwise as requested. */
    public function accessFrom(): ?Carbon
    {
        return $this->approved_at ? $this->access_from : $this->requested_from;
    }

    /** End of access: as approved once approved, otherwise as requested. Null means permanent. */
    public function accessUntil(): ?Carbon
    {
        return $this->approved_at ? $this->expires_at : $this->requested_until;
    }

    /** Whether IT approved a different window from the one requested. */
    public function windowChanged(): bool
    {
        return $this->approved_at !== null
            && ($this->access_from?->toIso8601String() !== $this->requested_from?->toIso8601String()
                || $this->expires_at?->toIso8601String() !== $this->requested_until?->toIso8601String());
    }

    public function isTemporary(): bool
    {
        return $this->accessUntil() !== null;
    }

    /** "1 Dec 9:00 am – 1 Dec 3:00 pm", "From 1 Dec 9:00 am", or "Permanent", in school time. */
    public function windowLabel(): string
    {
        $from = $this->accessFrom();
        $until = $this->accessUntil();

        return match (true) {
            $until !== null => ($from ? $from->local()->format('j M Y g:i a') : 'Approval').' – '.$until->local()->format('j M Y g:i a'),
            $from !== null => 'From '.$from->local()->format('j M Y g:i a').', permanent',
            default => 'Permanent',
        };
    }
}
