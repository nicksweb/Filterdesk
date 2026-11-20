<?php

namespace App\Notifications;

use App\Models\WebsiteRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RequestDecisionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  string|null  $stage  Overrides the subject wording: received or ending. */
    public function __construct(private readonly WebsiteRequest $websiteRequest, private readonly string $message, private readonly ?string $stage = null) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->websiteRequest;
        $headline = match ($this->stage ?? $request->status->value) {
            'received', 'pending' => 'received',
            'ending' => 'access ends soon',
            'approved', 'action_required', 'failed', 'active' => match (true) {
                (bool) $request->accessFrom()?->isFuture() => 'approved and scheduled',
                $request->status->value === 'active' => 'access is live',
                default => 'approved',
            },
            'needs_information' => 'more information needed',
            'rejected' => 'declined',
            'revoked' => 'access removed',
            'expired' => 'access has ended',
            default => strtolower($request->status->label()),
        };

        $mail = (new MailMessage)
            ->subject("Website request {$request->reference}: {$headline}")
            ->greeting("Hello {$notifiable->name},")
            ->line($this->message)
            ->line('Websites: '.implode(', ', $request->effectiveDomains()));

        if (in_array($request->status->value, ['pending', 'needs_information', 'approved', 'action_required', 'failed', 'active'], true)) {
            $mail->line('When: '.$request->windowLabel().' ('.config('app.display_timezone').' time).');
        }
        if ($request->status->value === 'needs_information') {
            $mail->line('Reply from the request page so IT can continue.');
        }

        return $mail->action('View request', route('requests.show', $request));
    }
}
