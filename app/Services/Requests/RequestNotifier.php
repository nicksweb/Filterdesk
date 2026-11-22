<?php

namespace App\Services\Requests;

use App\Models\User;
use App\Models\WebsiteRequest;
use App\Notifications\RequestActivityNotification;
use App\Notifications\RequestDecisionNotification;
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

    public function decision(WebsiteRequest $request, string $message): void
    {
        $this->send($request->requester, new RequestDecisionNotification($request, $message));
    }

    /** Confirmation to the requester that the request reached IT. */
    public function received(WebsiteRequest $request): void
    {
        if ($this->requesterEnabled('submitted')) {
            $this->send($request->requester, new RequestDecisionNotification($request, 'We have received your request. IT will review it and email you when there is a decision or a question.', 'received'));
        }
    }

    /** Warning that temporary access ends soon. */
    public function expiringSoon(WebsiteRequest $request): void
    {
        if ($this->requesterEnabled('expiry_reminder')) {
            $this->send($request->requester, new RequestDecisionNotification($request, 'Your access ends '.$request->expires_at->local()->format('l j M \a\t g:i a').'. If you still need it after that, submit a new request.', 'ending'));
        }
    }

    /** Temporary access has ended. */
    public function expired(WebsiteRequest $request): void
    {
        if ($this->requesterEnabled('expired')) {
            $this->send($request->requester, new RequestDecisionNotification($request, 'Your temporary access has ended and the websites are filtered as normal again. Submit a new request if you need them again.'));
        }
    }

    /** A controller filter drifted from its policy or cannot be read. */
    public function filterSyncAlert(BaseNotification $notification): void
    {
        if ($this->enabled('filter_sync')) {
            $this->toIt($notification, true);
        }
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

    private function requesterEnabled(string $event): bool
    {
        return (bool) $this->settings->get("notifications.requester.{$event}", true);
    }

    private function enabled(string $event): bool
    {
        return (bool) $this->settings->get("notifications.events.{$event}", true);
    }
}
