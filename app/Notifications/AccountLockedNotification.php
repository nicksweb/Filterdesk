<?php

namespace App\Notifications;

use App\Services\Notifications\EmailTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Repeated failed password sign-ins locked an account for a while. */
class AccountLockedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $email, public readonly string $ip, public readonly int $minutes) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return app(EmailTemplates::class)->mail('account_locked', [
            'name' => $notifiable->name ?? '', 'email' => $this->email, 'minutes' => (string) $this->minutes, 'ip' => (string) $this->ip,
        ], route('login'));
    }
}
