<?php

namespace App\Models;

use App\Enums\EntrySource;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyEntry extends Model
{
    protected $fillable = ['policy_list_id', 'domain', 'source', 'website_request_id', 'note', 'added_by_id', 'starts_at', 'expires_at'];

    protected function casts(): array
    {
        return [
            'source' => EntrySource::class,
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function policyList(): BelongsTo
    {
        return $this->belongsTo(PolicyList::class);
    }

    public function websiteRequest(): BelongsTo
    {
        return $this->belongsTo(WebsiteRequest::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_id');
    }

    /** @param Builder<self> $query */
    public function scopeEffective(Builder $query, ?CarbonInterface $at = null): void
    {
        $at ??= now();
        $query->where(fn (Builder $query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $at));
    }

    public function isEffective(?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return ($this->starts_at === null || $this->starts_at->lte($at))
            && ($this->expires_at === null || $this->expires_at->gt($at));
    }

    public function isScheduled(): bool
    {
        return $this->starts_at !== null && $this->starts_at->isFuture();
    }
}
