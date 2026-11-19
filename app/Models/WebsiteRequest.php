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
        'provider_state',
    ];

    protected function casts(): array
    {
        return [
            'domains' => 'array',
            'provider_state' => 'array',
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

    public function events(): HasMany
    {
        return $this->hasMany(RequestEvent::class);
    }
}
