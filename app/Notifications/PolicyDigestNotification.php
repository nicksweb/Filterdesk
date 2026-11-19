<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Daily summary of policy changes IT needs to make in the network provider. */
class PolicyDigestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param list<array{policy: string, slug: string, allow_add: int, allow_remove: int, block_add: int, block_remove: int, snapshot: bool}> $policies */
    public function __construct(public readonly array $policies, public readonly int $actionRequired, public readonly int $expiringSoon) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Daily web filter reconciliation')
            ->greeting('Web filter changes to review today');

        if ($this->actionRequired > 0) {
            $message->line("{$this->actionRequired} approved request(s) are waiting to be applied in the network controller.");
        }
        if ($this->expiringSoon > 0) {
            $message->line("{$this->expiringSoon} temporary entr(y/ies) expire in the next 24 hours.");
        }

        foreach ($this->policies as $policy) {
            $message->line($policy['snapshot']
                ? "{$policy['policy']}: allow +{$policy['allow_add']} / −{$policy['allow_remove']}, block +{$policy['block_add']} / −{$policy['block_remove']}."
                : "{$policy['policy']}: no controller snapshot yet. Paste the current lists to compare.");
        }

        return $message->action('Open policy lists', route('admin.policy.index'));
    }
}
