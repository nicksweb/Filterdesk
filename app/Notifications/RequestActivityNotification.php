<?php

namespace App\Notifications;

use App\Models\WebsiteRequest;
use App\Services\Notifications\EmailTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/** IT-facing email about a request: new submission, reply, assignment. */
class RequestActivityNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param list<string> $lines */
    public function __construct(
        public readonly WebsiteRequest $websiteRequest,
        public readonly string $headline,
        public readonly array $lines = [],
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->websiteRequest->loadMissing('requester');

        $lines = array_values($this->lines);
        // One-click decisions for IT: each link opens a confirmation page (sign-in still required).
        if ($request->status->isOpen()) {
            $lines[] = 'Decide now: approve '.URL::temporarySignedRoute('it.requests.quick', now()->addDays(7), ['websiteRequest' => $request, 'decision' => 'approve'])
                .' · decline '.URL::temporarySignedRoute('it.requests.quick', now()->addDays(7), ['websiteRequest' => $request, 'decision' => 'reject']);
        }

        return app(EmailTemplates::class)->mail('it_activity', RequestTemplateValues::for($request, $notifiable) + [
            'headline' => $this->headline, 'details' => $lines, 'websites' => implode(', ', $request->domains),
        ], route('requests.show', $request));
    }
}
