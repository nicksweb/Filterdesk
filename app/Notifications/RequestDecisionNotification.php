<?php

namespace App\Notifications;

use App\Models\WebsiteRequest;
use App\Services\Notifications\EmailTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RequestDecisionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string|null  $stage  Overrides the subject wording: received or ending.
     * @param  string|null  $note  IT's own words, for the {note} placeholder (the plain templates use it instead of {message}).
     */
    public function __construct(private readonly WebsiteRequest $websiteRequest, private readonly string $message, private readonly ?string $stage = null, private readonly ?string $note = null) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->websiteRequest;

        return app(EmailTemplates::class)->mail(self::templateFor($request, $this->stage), RequestTemplateValues::for($request, $notifiable)
            + ['message' => $this->message, 'note' => trim((string) $this->note)], route('requests.show', $request));
    }

    /** Which email a request gets at this point. */
    public static function templateFor(WebsiteRequest $request, ?string $stage = null): string
    {
        return match ($stage ?? $request->status->value) {
            'received', 'pending' => 'request_received',
            'ending' => 'request_ending',
            'approved', 'action_required', 'failed', 'active' => match (true) {
                (bool) $request->accessFrom()?->isFuture() => 'request_scheduled',
                $request->status->value === 'active' => 'request_live',
                default => 'request_approved',
            },
            'needs_information' => 'request_information',
            'rejected' => 'request_declined',
            'revoked' => 'request_revoked',
            'expired' => 'request_expired',
            default => 'request_approved',
        };
    }
}
