<?php

namespace App\Services\Policy;

use App\Models\PolicyEntry;
use App\Models\User;
use App\Notifications\ExceptionsDueNotification;
use App\Services\AuditLogger;
use App\Services\Requests\RequestNotifier;
use App\Services\SettingsRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The exception lifecycle: entries made by IT or from requests are reviewed
 * on a schedule. Owners and IT are told when one is due; keeping it moves
 * its review date on; one nobody reviews expires after the grace period
 * and leaves the controller through the normal push.
 */
class ExceptionReviews
{
    public function __construct(private readonly AuditLogger $audit, private readonly SettingsRepository $settings) {}

    /**
     * Exceptions due now or within the grace period, oldest first.
     *
     * @return Builder<PolicyEntry>
     */
    public function due(?User $owner = null): Builder
    {
        return PolicyEntry::query()->reviewable()->whereNull('pending_action')
            ->where('review_at', '<=', now()->addDays(PolicyEntry::graceDays()))
            ->when($owner, fn (Builder $query) => $query->where('owner_id', $owner->id))
            ->with(['policyList', 'owner', 'websiteRequest:id,reference,requester_id'])->orderBy('review_at');
    }

    /** @param Collection<int, PolicyEntry> $entries */
    public function keep(Collection $entries, User $user): int
    {
        foreach ($entries as $entry) {
            $entry->forceFill(['review_at' => now()->addMonths(PolicyEntry::reviewMonths()), 'reviewed_at' => now(), 'reviewed_by_id' => $user->id, 'review_notified_at' => null])->save();
            $this->audit->log('policy.exception_kept', $entry->policyList, ['domain' => $entry->domain, 'until' => $entry->review_at->toDateString()]);
        }

        return $entries->count();
    }

    /**
     * Email owners (and IT) about exceptions that became due, once each.
     *
     * @return int exceptions notified
     */
    public function notifyDue(): int
    {
        $entries = PolicyEntry::query()->reviewable()->whereNull('pending_action')->where('review_at', '<=', now())->whereNull('review_notified_at')->with(['policyList', 'owner'])->get();
        if ($entries->isEmpty()) {
            return 0;
        }
        $line = fn (PolicyEntry $entry) => "{$entry->domain} ({$entry->policyList->name})";
        $expires = fn (Collection $group) => $group->min(fn (PolicyEntry $entry) => $entry->expiresUnreviewedAt())->local()->format('j M Y');

        foreach ($entries->groupBy('owner_id') as $group) {
            $owner = $group->first()->owner;
            if ($owner?->enabled) {
                rescue(fn () => $owner->notify(new ExceptionsDueNotification($owner->name, $group->map($line)->all(), $expires($group))), null, true);
            }
        }
        $address = $this->settings->get('notifications.it_address');
        if (filled($address)) {
            $itLines = $entries->map(fn (PolicyEntry $entry) => $line($entry).' · owner '.($entry->owner?->name ?? 'none'))->all();
            app(RequestNotifier::class)->itAlert('exceptions_due', count($itLines).' exception(s) due for review, removed on '.$expires($entries).' if nobody keeps them: '.implode(', ', array_slice($itLines, 0, 10)).(count($itLines) > 10 ? '…' : '').'.',
                new ExceptionsDueNotification('IT team', $itLines, $expires($entries)));
        }
        PolicyEntry::query()->whereKey($entries->pluck('id'))->update(['review_notified_at' => now()]);

        return $entries->count();
    }

    /** Expire exceptions whose grace period has passed without a review. */
    public function expireOverdue(): int
    {
        $overdue = PolicyEntry::query()->reviewable()->whereNull('pending_action')->where('review_at', '<=', now()->subDays(PolicyEntry::graceDays()))->with('policyList')->get();
        foreach ($overdue as $entry) {
            try {
                $entry->forceFill(['expires_at' => now(), 'note' => trim(($entry->note ? "{$entry->note} · " : '').'Expired: not reviewed by '.$entry->expiresUnreviewedAt()->local()->format('j M Y'))])->save();
                $this->audit->log('policy.exception_expired_unreviewed', $entry->policyList, ['domain' => $entry->domain, 'review_at' => $entry->review_at->toDateString()]);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $overdue->count();
    }
}
