<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One write of a policy's lists to a controller content filter. */
class ControllerPush extends Model
{
    protected $fillable = [
        'filter_policy_id', 'user_id', 'provider_reference', 'provider_name', 'trigger', 'status',
        'before_allow', 'before_block', 'after_allow', 'after_block', 'changes', 'message', 'rollback_of_id',
    ];

    protected function casts(): array
    {
        return [
            'before_allow' => 'array', 'before_block' => 'array',
            'after_allow' => 'array', 'after_block' => 'array',
            'changes' => 'array',
        ];
    }

    public function filterPolicy(): BelongsTo
    {
        return $this->belongsTo(FilterPolicy::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function changeCount(): int
    {
        return collect($this->changes)->sum(fn ($values) => count($values));
    }
}
