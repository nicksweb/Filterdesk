<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent synchronously from Settings so SMTP errors surface immediately. */
class TestEmailNotification extends Notification
{
    public function __construct(public readonly string $requestedBy) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('IT Requests test email')
            ->line("This test was sent by {$this->requestedBy}. Email delivery is working.")
            ->action('Open IT Requests', route('dashboard'));
    }
}
