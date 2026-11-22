<?php

namespace App\Models;

use App\Casts\AsReach;
use App\Enums\RequestStatus;
use App\Support\Reach;
use App\Support\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class WebsiteRequest extends Model
{
    protected $fillable = [
        'reference', 'request_type', 'domain_categories', 'access_plan', 'schedule', 'requester_id', 'assigned_to_id', 'domains', 'access_scope',
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
            'applied_scope' => AsReach::class,
            'assigned_at' => 'datetime',
            'access_scope' => AsReach::class,
            'domain_categories' => 'array',
            'access_plan' => 'array',
            'schedule' => 'array',
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

    /**
     * Hostnames that got an allow (or, for blocks, block) entry. With a smart
     * approval, hostnames that only needed a block removed, or nothing, are left out.
     *
     * @return list<string>
     */
    public function listedDomains(): array
    {
        $plan = $this->access_plan ?? [];

        return array_values(array_filter($this->effectiveDomains(), fn (string $domain) => ($plan[$domain]['action'] ?? 'allow') === 'allow'));
    }

    public function effectiveScope(): Reach
    {
        return $this->applied_scope ?? $this->access_scope;
    }

    /** The request asks for the websites to be blocked rather than allowed. */
    public function isBlock(): bool
    {
        return $this->request_type === 'block';
    }

    public function typeLabel(): string
    {
        return $this->isBlock() ? 'Block' : 'Access';
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

    /** The recurring pattern, when the request has one. */
    public function recurrence(): ?Schedule
    {
        return Schedule::fromArray($this->schedule);
    }

    public function isTemporary(): bool
    {
        return $this->accessUntil() !== null;
    }

    /** "1 Dec 9:00 am – 1 Dec 3:00 pm", "From 1 Dec 9:00 am", or "Permanent", in school time. */
    public function windowLabel(): string
    {
        if ($schedule = $this->recurrence()) {
            return 'Recurring: '.$schedule->label();
        }
        $from = $this->accessFrom();
        $until = $this->accessUntil();

        return match (true) {
            $until !== null => ($from ? $from->local()->format('j M Y g:i a') : 'Approval').' – '.$until->local()->format('j M Y g:i a'),
            $from !== null => 'From '.$from->local()->format('j M Y g:i a').', permanent',
            default => 'Permanent',
        };
    }
}
