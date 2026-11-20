<?php

namespace App\Models;

use App\Enums\EntrySource;
use App\Services\SettingsRepository;
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
    /**
     * Minutes a timed entry takes effect before its start and stays after
     * its end, so the controller has it in place for the whole window even
     * allowing for sync delay.
     *
     * @return array{lead: int, lag: int}
     */
    public static function margins(): array
    {
        $settings = app(SettingsRepository::class);

        return [
            'lead' => max(0, (int) $settings->get('approval.window_lead_minutes', 5)),
            'lag' => max(0, (int) $settings->get('approval.window_lag_minutes', 5)),
        ];
    }

    public function scopeEffective(Builder $query, ?CarbonInterface $at = null): void
    {
        $at ??= now();
        ['lead' => $lead, 'lag' => $lag] = self::margins();
        $query->where(fn (Builder $query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $at->copy()->addMinutes($lead)))
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $at->copy()->subMinutes($lag)));
    }

    /** When the entry is actually put in place (start minus the lead margin). */
    public function effectiveFrom(): ?CarbonInterface
    {
        return $this->starts_at?->copy()->subMinutes(self::margins()['lead']);
    }

    /** When the entry is actually taken out (end plus the lag margin). */
    public function effectiveUntil(): ?CarbonInterface
    {
        return $this->expires_at?->copy()->addMinutes(self::margins()['lag']);
    }

    public function isEffective(?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return ($this->starts_at === null || $this->effectiveFrom()->lte($at))
            && ($this->expires_at === null || $this->effectiveUntil()->gt($at));
    }

    public function isScheduled(): bool
    {
        return $this->starts_at !== null && $this->effectiveFrom()->isFuture();
    }
}
