<?php

namespace App\Notifications;

use App\Services\Notifications\EmailTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells an owner (or IT) which of their website exceptions are due for review. */
class ExceptionsDueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param list<string> $lines */
    public function __construct(public readonly string $name, public readonly array $lines, public readonly string $expires) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return app(EmailTemplates::class)->mail('exceptions_due', ['name' => $this->name, 'details' => $this->lines, 'expires' => $this->expires], route('reviews.index'));
    }
}
