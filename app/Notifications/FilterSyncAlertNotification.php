<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent when a controller filter starts to differ from its policy, or cannot be read. */
class FilterSyncAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param list<array{policy: string, slug: string, status: string, summary: string}> $policies */
    public function __construct(public readonly array $policies) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Web filter out of sync')
            ->greeting('A content filter needs attention');

        foreach ($this->policies as $policy) {
            $message->line(($policy['status'] === 'error' ? 'Cannot check ' : 'Changed outside IT Requests: ')."{$policy['policy']}. {$policy['summary']}");
        }

        return $message->action('Open policy lists', route('admin.policy.index'));
    }
}
