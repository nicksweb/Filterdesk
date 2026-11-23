<?php

namespace App\Models;

use App\Enums\ListKind;
use App\Enums\ListScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A named allow or block list. Scope decides which filter policies inherit it:
 * site-wide lists reach every policy, audience lists reach every policy for
 * that audience, and policy lists reach one policy.
 */
class PolicyList extends Model
{
    protected $fillable = ['name', 'kind', 'scope', 'audience', 'filter_policy_id', 'description', 'is_default', 'sort_order', 'purpose'];

    protected function casts(): array
    {
        return [
            'kind' => ListKind::class,
            'scope' => ListScope::class,
            'is_default' => 'boolean',
        ];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(PolicyEntry::class);
    }

    public function filterPolicy(): BelongsTo
    {
        return $this->belongsTo(FilterPolicy::class);
    }

    /** @param Builder<self> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderByRaw("case scope when 'site' then 0 when 'audience' then 1 else 2 end")
            ->orderBy('audience')->orderBy('kind')->orderBy('sort_order')->orderBy('name');
    }

    /** @param Builder<self> $query */
    /**
     * Lists that reach a policy. A layer sees its parent's lists plus its own,
     * because it carries the parent's feeds.
     */
    public function scopeAppliesTo(Builder $query, FilterPolicy $policy): void
    {
        if ($policy->isLayer() && $policy->layerOf) {
            $query->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->appliesTo($policy->layerOf))
                ->orWhere(fn (Builder $query) => $query->where('scope', ListScope::Policy)->where('filter_policy_id', $policy->id)));

            return;
        }

        // A site-wide layer serves every network type, so it takes no type's lists.
        $query->where(fn (Builder $query) => $query
            ->where('scope', ListScope::Site)
            ->when(! $policy->isSitewide(), fn (Builder $query) => $query->orWhere(fn (Builder $query) => $query->where('scope', ListScope::Audience)->where('audience', $policy->audience)))
            ->orWhere(fn (Builder $query) => $query->where('scope', ListScope::Policy)->where('filter_policy_id', $policy->id)));
    }

    /** The blocklist feed that fills this list, if any. */
    public function feed(): HasOne
    {
        return $this->hasOne(ListSource::class);
    }

    public function appliesTo(FilterPolicy $policy): bool
    {
        return match ($this->scope) {
            ListScope::Site => true,
            ListScope::Audience => ! $policy->isSitewide() && $this->audience === $policy->audience,
            ListScope::Policy => $this->filter_policy_id === $policy->id || ($policy->layer_of_id !== null && $this->filter_policy_id === $policy->layer_of_id),
        };
    }

    /** The network type this belongs to (its shared allow and block lists). */
    public function networkType(): BelongsTo
    {
        return $this->belongsTo(NetworkType::class, 'audience', 'key');
    }

    public function typeLabel(): string
    {
        return NetworkType::labelFor($this->audience);
    }

    public function scopeLabel(): string
    {
        return match ($this->scope) {
            ListScope::Site => 'Site-wide',
            ListScope::Audience => NetworkType::labelFor($this->audience),
            ListScope::Policy => $this->filterPolicy?->name ?? 'Single policy',
        };
    }
}
