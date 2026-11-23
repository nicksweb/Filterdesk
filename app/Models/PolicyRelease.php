<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One published policy version: the controller writes made together by a
 * publish, an automatic push or a schedule.
 */
class PolicyRelease extends Model
{
    protected $fillable = ['number', 'trigger', 'status', 'user_id', 'summary', 'firewall', 'rollback_of_id', 'finished_at'];

    protected function casts(): array
    {
        return ['firewall' => 'array', 'finished_at' => 'datetime'];
    }

    public function pushes(): HasMany
    {
        return $this->hasMany(ControllerPush::class)->orderBy('id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rollbackOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rollback_of_id');
    }

    public function rolledBackBy(): ?self
    {
        return self::query()->where('rollback_of_id', $this->id)->latest('id')->first();
    }

    public function label(): string
    {
        return $this->number ? "v{$this->number}" : 'unnumbered';
    }

    public function triggerLabel(): string
    {
        return match ($this->trigger) {
            'manual' => 'Published by hand',
            'approval', 'request' => 'After a request decision',
            'schedule' => 'Scheduled push',
            'window' => 'Timed access opening or closing',
            'category_guard' => 'Category guard',
            'rollback' => 'Rollback',
            'restore' => 'Restored entry',
            'feed' => 'Blocklist refresh',
            default => ucfirst(str_replace('_', ' ', $this->trigger)),
        };
    }

    public function changeCount(): int
    {
        return $this->pushes->sum(fn (ControllerPush $push) => $push->changeCount())
            + collect($this->firewall ?? [])->where('changed', true)->count();
    }

    public function canRollBack(): bool
    {
        return $this->status === 'published' && $this->rollback_of_id === null && $this->rolledBackBy() === null
            && $this->pushes->contains(fn (ControllerPush $push) => $push->status === 'ok' && $push->rollback_of_id === null);
    }
}
