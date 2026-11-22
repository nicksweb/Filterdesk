<?php

namespace App\Notifications;

use App\Services\Notifications\EmailTemplates;
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
        return app(EmailTemplates::class)->mail('test', ['sender' => (string) $this->requestedBy], route('dashboard'));
    }
}
