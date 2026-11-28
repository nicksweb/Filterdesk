<?php

namespace App\Services\Requests;

use App\Models\PendingAlert;
use App\Models\User;
use App\Models\WebsiteRequest;
use App\Notifications\FilterSyncAlertNotification;
use App\Notifications\RequestActivityNotification;
use App\Notifications\RequestDecisionNotification;
use App\Notifications\TwoPersonOverrideNotification;
use App\Services\SettingsRepository;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Decides who hears about request activity. Each event type can be turned
 * off in Settings → Notifications. Failures are reported, never thrown, so a
 * mail problem cannot block an approval.
 */
class RequestNotifier
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function submitted(WebsiteRequest $request): void
    {
        if (! $this->enabled('new_request')) {
            return;
        }

        $this->toIt(new RequestActivityNotification($request, $request->isBlock() ? 'New website block request' : 'New website access request', [
            app(RequestFormSettings::class)->label($request->isBlock()).': '.(filled($request->use_case) ? str($request->use_case)->limit(400) : 'not given'),
        ]), includeOptedInStaff: true);
    }

    public function replied(WebsiteRequest $request, string $reply): void
    {
        if (! $this->enabled('requester_reply')) {
            return;
        }

        $notification = new RequestActivityNotification($request, 'Requester provided more information', ['Reply: '.str($reply)->limit(600)]);
        $request->assignedTo ? $this->send($request->assignedTo, $notification) : $this->toIt($notification, includeOptedInStaff: true);
    }

    public function assigned(WebsiteRequest $request, User $assignee, User $actor): void
    {
        if (! $this->enabled('assignment') || $assignee->is($actor)) {
            return;
        }

        $this->send($assignee, new RequestActivityNotification($request, "Assigned to you by {$actor->name}"));
    }

    /** Ask the other IT officers to confirm a first approval. */
    public function secondApprovalNeeded(WebsiteRequest $request, User $first, array $reasons): void
    {
        $notification = new RequestActivityNotification($request, "Second approval needed ({$first->name} approved)", [
            'Needs two IT officers: '.implode('; ', $reasons).'.',
        ]);
        User::query()->itStaff()->whereKeyNot($first->id)->each(fn (User $user) => $this->send($user, $notification));
    }

    /**
     * An officer approved alone where two were needed: tell the other
     * officers and the IT address, so someone reviews it afterwards.
     *
     * @param  list<string>  $reasons
     */
    public function twoPersonOverride(User $actor, string $subject, array $reasons, string $why, string $url): void
    {
        $notification = new TwoPersonOverrideNotification($actor->name, $subject, $reasons, $why, $url);
        User::query()->itStaff()->whereKeyNot($actor->id)->each(fn (User $user) => $this->send($user, $notification));
        $address = $this->settings->get('notifications.it_address');
        if (filled($address) && strtolower((string) $address) !== strtolower($actor->email)) {
            rescue(fn () => Notification::route('mail', $address)->notify($notification), null, true);
        }
    }

    public function decision(WebsiteRequest $request, string $message, ?string $note = null): void
    {
        // The optional steps can be switched off (Settings → Email and notifications). With "approved" off, the
        // requester hears once, when it works; the outcome emails (allowed, declined, a question) always go.
        $optional = ['request_approved' => 'approved', 'request_scheduled' => 'scheduled', 'request_revoked' => 'revoked'];
        $event = $optional[RequestDecisionNotification::templateFor($request)] ?? null;
        if ($event !== null && ! $this->requesterEnabled($event, $request->requester)) {
            return;
        }
        $this->send($request->requester, new RequestDecisionNotification($request, $message, null, $note));
    }

    /** Confirmation to the requester that the request reached IT. */
    public function received(WebsiteRequest $request): void
    {
        if ($this->requesterEnabled('submitted', $request->requester)) {
            $this->send($request->requester, new RequestDecisionNotification($request, 'We have received your request. IT will review it and email you when there is a decision or a question.', 'received'));
        }
    }

    /** Warning that temporary access ends soon. */
    public function expiringSoon(WebsiteRequest $request): void
    {
        if ($this->requesterEnabled('expiry_reminder', $request->requester)) {
            $this->send($request->requester, new RequestDecisionNotification($request, 'Your access ends '.$request->expires_at->local()->format('l j M \a\t g:i a').'. If you still need it after that, submit a new request.', 'ending'));
        }
    }

    /** Temporary access has ended. */
    public function expired(WebsiteRequest $request): void
    {
        if ($this->requesterEnabled('expired', $request->requester)) {
            $this->send($request->requester, new RequestDecisionNotification($request, 'Your temporary access has ended and the websites are filtered as normal again. Submit a new request if you need them again.'));
        }
    }

    /**
     * A controller filter drifted from its policy or cannot be read, a list
     * grew a new part, an allow list entry moved… Sent straight away, or
     * (the default) held for the next summary so IT gets one email.
     */
    public function filterSyncAlert(FilterSyncAlertNotification $notification): void
    {
        if (! $this->enabled('filter_sync')) {
            return;
        }
        if (self::alertsMode($this->settings) === 'now') {
            $this->toIt($notification, true);

            return;
        }
        foreach ($notification->lines() as $line) {
            PendingAlert::query()->create(['kind' => 'filter_sync', 'line' => $line]);
        }
    }

    /** Hold one line for the summary, or send it now as its own alert. */
    public function itAlert(string $kind, string $line, ?BaseNotification $now = null): void
    {
        if (self::alertsMode($this->settings) === 'now' && $now !== null) {
            $address = $this->settings->get('notifications.it_address');
            if (filled($address)) {
                rescue(fn () => Notification::route('mail', $address)->notify($now), null, true);
            }

            return;
        }
        PendingAlert::query()->create(['kind' => $kind, 'line' => $line]);
    }

    /** 'summary' (default): IT alerts wait for the next summary email; 'now': each is emailed when it happens. */
    public static function alertsMode(SettingsRepository $settings): string
    {
        return $settings->get('notifications.alerts', 'summary') === 'now' ? 'now' : 'summary';
    }

    private function toIt(BaseNotification $notification, bool $includeOptedInStaff): void
    {
        $address = $this->settings->get('notifications.it_address');
        if (filled($address)) {
            try {
                Notification::route('mail', $address)->notify($notification);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        if ($includeOptedInStaff) {
            User::query()->itStaff()->where('notify_new_requests', true)
                ->when(filled($address), fn ($query) => $query->where('email', '!=', strtolower((string) $address)))
                ->each(fn (User $user) => $this->send($user, $notification));
        }
    }

    private function send(?User $user, BaseNotification $notification): void
    {
        if (! $user || ! $user->enabled) {
            return;
        }

        try {
            $user->notify($notification);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** On for everyone (Settings → Email and notifications), and this person hasn't chosen fewer emails. */
    private function requesterEnabled(string $event, ?User $requester = null): bool
    {
        return (bool) $this->settings->get("notifications.requester.{$event}", true) && ($requester?->wantsEmail($event) ?? true);
    }

    private function enabled(string $event): bool
    {
        return (bool) $this->settings->get("notifications.events.{$event}", true);
    }
}
