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

    public function __construct(private readonly WebsiteRequest $websiteRequest, private readonly string $message) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Website request {$this->websiteRequest->reference}: {$this->websiteRequest->status->label()}")
            ->greeting("Hello {$notifiable->name},")
            ->line($this->message)
            ->line('Domains: '.implode(', ', $this->websiteRequest->domains))
            ->action('View request', route('requests.show', $this->websiteRequest));
    }
}
