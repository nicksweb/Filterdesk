<?php

namespace App\Notifications;

use App\Services\Notifications\EmailTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The summary for IT: things that may need attention, and the alerts held since the last one. */
class PolicyDigestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<array{policy: string, slug: string, allow_add: int, allow_remove: int, block_add: int, block_remove: int, snapshot: bool}>  $policies  filters not in step
     * @param  list<string>  $alerts
     */
    public function __construct(
        public readonly array $policies,
        public readonly int $actionRequired,
        public readonly int $expiringSoon,
        public readonly int $waiting = 0,
        public readonly int $reviews = 0,
        public readonly array $alerts = [],
        public readonly int $days = 1,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $details = [];
        if ($this->waiting > 0) {
            $details[] = "{$this->waiting} request(s) waiting for a decision.";
        }
        if ($this->actionRequired > 0) {
            $details[] = "{$this->actionRequired} approved request(s) waiting to be applied in the network controller.";
        }
        if ($this->reviews > 0) {
            $details[] = "{$this->reviews} exception(s) due for review.";
        }
        if ($this->expiringSoon > 0) {
            $details[] = "{$this->expiringSoon} temporary entr(y/ies) end in the next ".($this->days === 1 ? '24 hours' : "{$this->days} days").'.';
        }
        foreach ($this->policies as $policy) {
            $details[] = $policy['snapshot']
                ? "{$policy['policy']}: changes to push, allow +{$policy['allow_add']} / −{$policy['allow_remove']}, block +{$policy['block_add']} / −{$policy['block_remove']}."
                : "{$policy['policy']}: no controller snapshot yet. Paste the current lists to compare.";
        }
        if ($this->alerts !== []) {
            $details[] = 'Since the last summary:';
            foreach (array_slice($this->alerts, 0, 40) as $alert) {
                $details[] = "• {$alert}";
            }
            if (count($this->alerts) > 40) {
                $details[] = '• and '.(count($this->alerts) - 40).' more (see the audit log).';
            }
        }
        if ($details === []) {
            $details[] = 'Nothing needs attention.';
        }

        return app(EmailTemplates::class)->mail('policy_digest', ['details' => $details], route('dashboard'));
    }
}
