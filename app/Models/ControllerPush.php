<?php

namespace App\Models;

use App\Services\Policy\StateHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One write of a policy's lists to a controller content filter. */
class ControllerPush extends Model
{
    /** A successful write is a new version of that filter (Changes → Restore to a point in time). */
    protected static function booted(): void
    {
        static::created(function (ControllerPush $push): void {
            if ($push->status === 'ok' && filled($push->provider_reference)) {
                rescue(fn () => app(StateHistory::class)->recordFilter(['id' => $push->provider_reference, 'name' => $push->provider_name ?: $push->provider_reference,
                    'allow' => $push->after_allow ?? [], 'block' => $push->after_block ?? []], $push->trigger === 'restore' ? 'restore' : 'push'), null, true);
            }
        });
    }

    protected $fillable = [
        'policy_release_id', 'filter_policy_id', 'user_id', 'provider_reference', 'provider_name', 'trigger', 'status',
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

    public function release(): BelongsTo
    {
        return $this->belongsTo(PolicyRelease::class, 'policy_release_id');
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
