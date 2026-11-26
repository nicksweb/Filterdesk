<?php

namespace App\Notifications;

use App\Services\Notifications\EmailTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent when a controller filter starts to differ from its policy, cannot be read, or a network was given baseline filtering. */
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
        $details = collect($this->policies)->map(fn (array $policy) => match ($policy['status']) {
            'error' => 'Cannot check ', 'baseline' => 'Baseline filtering: ', 'paused' => 'Paused in UniFi: ', 'firewall' => 'Firewall allow list ', default => 'Changed outside Filterdesk: '
        }."{$policy['policy']}. {$policy['summary']}")->all();

        return app(EmailTemplates::class)->mail('filter_sync_alert', ['details' => $details], route('admin.policy.index'));
    }
}
