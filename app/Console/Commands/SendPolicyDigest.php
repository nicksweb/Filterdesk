<?php

namespace App\Console\Commands;

use App\Enums\RequestStatus;
use App\Models\FilterPolicy;
use App\Models\PendingAlert;
use App\Models\PolicyEntry;
use App\Models\WebsiteRequest;
use App\Notifications\PolicyDigestNotification;
use App\Services\Policy\PolicyEngine;
use App\Services\SettingsRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * One email to IT when something may need them: requests waiting, filters
 * out of step with their policy, exceptions due, and the alerts held since
 * the last one. Weekly by default (Settings → Email and notifications),
 * daily or off; nothing is sent when there's nothing to look at.
 */
class SendPolicyDigest extends Command
{
    protected $signature = 'policy:digest {--force : Send now, even on another day or when nothing needs attention}';

    protected $description = 'Email IT the summary of things that may need attention';

    public function handle(PolicyEngine $engine, SettingsRepository $settings): int
    {
        $address = $settings->get('notifications.it_address');
        $frequency = self::frequency($settings);
        if (blank($address) || (! $this->option('force') && $frequency === 'off')) {
            return self::SUCCESS;
        }
        $today = Carbon::now($settings->timezone());
        if (! $this->option('force') && $frequency === 'weekly' && $today->isoWeekday() !== self::weekday($settings)) {
            return self::SUCCESS;
        }
        $ahead = $frequency === 'weekly' ? 7 : 1;

        $policies = FilterPolicy::query()->where('enabled', true)->ordered()->get()->map(function (FilterPolicy $policy) use ($engine): array {
            $report = $engine->reconcile($policy);

            return [
                'policy' => $policy->name, 'slug' => $policy->slug, 'snapshot' => $report['has_snapshot'], 'in_sync' => $report['in_sync'],
                'allow_add' => count($report['allow_add']), 'allow_remove' => count($report['allow_remove']),
                'block_add' => count($report['block_add']), 'block_remove' => count($report['block_remove']),
            ];
        })->reject(fn (array $policy) => $policy['in_sync'])->values();
        $actionRequired = WebsiteRequest::query()->whereIn('status', [RequestStatus::Approved, RequestStatus::ActionRequired, RequestStatus::Failed])->count();
        $waiting = WebsiteRequest::query()->whereIn('status', [RequestStatus::Pending, RequestStatus::AwaitingSecondApproval])->count();
        $expiring = PolicyEntry::query()->whereBetween('expires_at', [now(), now()->addDays($ahead)])->count();
        $reviews = PolicyEntry::query()->reviewable()->whereNull('pending_action')->where('review_at', '<=', now())->count();
        $alerts = PendingAlert::query()->whereNull('sent_at')->orderBy('id')->get();

        if (! $this->option('force') && $actionRequired + $waiting + $reviews === 0 && $alerts->isEmpty() && $policies->isEmpty()) {
            $this->info('Nothing to report.');

            return self::SUCCESS;
        }

        Notification::route('mail', $address)->notify(new PolicyDigestNotification($policies->all(), $actionRequired, $expiring, $waiting, $reviews,
            $alerts->map(fn (PendingAlert $alert) => $alert->line)->unique()->values()->all(), $ahead));
        PendingAlert::query()->whereKey($alerts->pluck('id'))->update(['sent_at' => now()]);
        PendingAlert::query()->where('sent_at', '<', now()->subDays(60))->delete();
        $this->info("Summary sent to {$address}.");

        return self::SUCCESS;
    }

    /** 'weekly' (default), 'daily' or 'off'. Installs that had turned the daily digest off stay off. */
    public static function frequency(SettingsRepository $settings): string
    {
        $frequency = $settings->get('notifications.summary');
        if ($frequency === null) {
            return $settings->get('notifications.daily_digest', true) ? 'weekly' : 'off';
        }

        return in_array($frequency, ['weekly', 'daily', 'off'], true) ? $frequency : 'weekly';
    }

    /** ISO day of the week for the weekly summary: 1 Monday … 7 Sunday. */
    public static function weekday(SettingsRepository $settings): int
    {
        $day = (int) $settings->get('notifications.summary_day', 1);

        return $day >= 1 && $day <= 7 ? $day : 1;
    }
}
