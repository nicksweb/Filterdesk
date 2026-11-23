<?php

namespace App\Notifications;

use App\Services\Notifications\EmailTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the other IT officers that someone approved alone where two were needed. */
class TwoPersonOverrideNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param list<string> $reasons */
    public function __construct(public readonly string $officer, public readonly string $subject, public readonly array $reasons, public readonly string $why, public readonly string $url) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return app(EmailTemplates::class)->mail('two_person_override', [
            'officer' => $this->officer, 'subject' => $this->subject, 'reasons' => implode('; ', $this->reasons), 'why' => $this->why,
        ], $this->url);
    }
}
