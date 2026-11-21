<?php

namespace App\Notifications;

use App\Models\WebsiteRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

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
        $message = (new MailMessage)
            ->subject("{$request->reference}: {$this->headline}")
            ->greeting($this->headline)
            ->line('Domains: '.implode(', ', $request->domains))
            ->line("Requested by {$request->requester->name} · {$request->access_scope?->label()} · {$request->category}");

        foreach ($this->lines as $line) {
            $message->line($line);
        }

        return $message->action('Open request', route('requests.show', $request));
    }
}
