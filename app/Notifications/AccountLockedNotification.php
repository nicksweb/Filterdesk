<?php

namespace App\Notifications;

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
        return (new MailMessage)
            ->subject('Sign-in locked after failed attempts')
            ->line("Password sign-in for {$this->email} was locked for {$this->minutes} minutes after repeated failed attempts.")
            ->line("Last attempt from {$this->ip}.")
            ->line('If this was not you, tell IT. Administrators can turn off password sign-in for an account under Users, or turn off emergency local sign-in entirely under Settings → Authentication.')
            ->action('Open IT Requests', route('login'));
    }
}
