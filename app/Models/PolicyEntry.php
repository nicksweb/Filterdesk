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
    protected $fillable = ['policy_list_id', 'domain', 'source', 'website_request_id', 'list_source_id', 'note', 'added_by_id', 'starts_at', 'expires_at', 'schedule', 'pending_action', 'proposed_by_id', 'proposed_at',
        'owner_id', 'review_at', 'reviewed_at', 'reviewed_by_id', 'review_notified_at', 'last_seen_at', 'hours'];

    protected function casts(): array
    {
        return [
            'source' => EntrySource::class,
            'starts_at' => 'datetime',
            'schedule' => 'array',
            'expires_at' => 'datetime',
            'proposed_at' => 'datetime',
            'review_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'review_notified_at' => 'datetime',
            'last_seen_at' => 'datetime',
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

    /**
     * Exceptions made on purpose (by IT or from a request) are owned and
     * reviewed; feed and import entries belong to their list.
     */
    protected static function booted(): void
    {
        static::creating(function (self $entry): void {
            if (! in_array($entry->source, [EntrySource::Manual, EntrySource::Request], true)) {
                return;
            }
            $entry->owner_id ??= $entry->website_request_id ? WebsiteRequest::query()->whereKey($entry->website_request_id)->value('requester_id') : $entry->added_by_id;
            $entry->review_at ??= now()->addMonths(self::reviewMonths());
        });
    }

    /** Months between reviews of an exception. */
    public static function reviewMonths(): int
    {
        return max(1, min(36, (int) app(SettingsRepository::class)->get('governance.review_months', 12)));
    }

    /** Days an exception stays after its review date before it expires unreviewed. */
    public static function graceDays(): int
    {
        return max(1, min(90, (int) app(SettingsRepository::class)->get('governance.grace_days', 14)));
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }

    /** @param Builder<self> $query */
    public function scopeReviewable(Builder $query): void
    {
        $query->whereIn('source', [EntrySource::Manual->value, EntrySource::Request->value])->whereNotNull('review_at')
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /** When it expires if nobody reviews it. */
    public function expiresUnreviewedAt(): ?CarbonInterface
    {
        return $this->review_at?->copy()->addDays(self::graceDays());
    }

    /**
     * Record that hostnames came up in a check or request, so entries for
     * them (or a parent domain) are not flagged as stale.
     *
     * @param  list<string>  $hosts
     */
    public static function markSeen(array $hosts): void
    {
        $candidates = [];
        foreach ($hosts as $host) {
            $labels = explode('.', strtolower($host));
            for ($index = 0; $index < count($labels) - 1; $index++) {
                $candidates[] = implode('.', array_slice($labels, $index));
            }
        }
        if ($candidates !== []) {
            rescue(fn () => static::query()->whereIn('domain', array_values(array_unique($candidates)))->update(['last_seen_at' => now()]), null, false);
        }
    }

    /** Not seen in a check or request for a year. */
    public function isStale(): bool
    {
        return ($this->last_seen_at ?? $this->created_at)?->lt(now()->subYear()) ?? false;
    }

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by_id');
    }

    /** Waiting for a second IT officer: a proposed addition is not live yet, a proposed removal still is. */
    public function isPending(): bool
    {
        return $this->pending_action !== null;
    }

    /** @param Builder<self> $query */
    public function scopePending(Builder $query): void
    {
        $query->whereNotNull('pending_action');
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
        $query->where(fn (Builder $query) => $query->whereNull('pending_action')->orWhere('pending_action', 'remove'))
            ->where(fn (Builder $query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $at->copy()->addMinutes($lead)))
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

        return $this->pending_action !== 'add' && ($this->starts_at === null || $this->effectiveFrom()->lte($at))
            && ($this->expires_at === null || $this->effectiveUntil()->gt($at));
    }

    public function isScheduled(): bool
    {
        return $this->starts_at !== null && $this->effectiveFrom()->isFuture();
    }
}
