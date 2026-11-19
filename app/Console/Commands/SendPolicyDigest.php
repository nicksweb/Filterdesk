<?php

namespace App\Console\Commands;

use App\Enums\RequestStatus;
use App\Models\FilterPolicy;
use App\Models\PolicyEntry;
use App\Models\WebsiteRequest;
use App\Notifications\PolicyDigestNotification;
use App\Services\Policy\PolicyEngine;
use App\Services\SettingsRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class SendPolicyDigest extends Command
{
    protected $signature = 'policy:digest {--force : Send even when nothing needs attention}';

    protected $description = 'Email IT the daily web filter reconciliation summary';

    public function handle(PolicyEngine $engine, SettingsRepository $settings): int
    {
        $address = $settings->get('notifications.it_address');
        if (blank($address) || (! $this->option('force') && ! $settings->get('notifications.daily_digest', true))) {
            return self::SUCCESS;
        }

        $policies = FilterPolicy::query()->where('enabled', true)->ordered()->get()->map(function (FilterPolicy $policy) use ($engine): array {
            $report = $engine->reconcile($policy);

            return [
                'policy' => $policy->name, 'slug' => $policy->slug, 'snapshot' => $report['has_snapshot'], 'in_sync' => $report['in_sync'],
                'allow_add' => count($report['allow_add']), 'allow_remove' => count($report['allow_remove']),
                'block_add' => count($report['block_add']), 'block_remove' => count($report['block_remove']),
            ];
        });
        $actionRequired = WebsiteRequest::query()->whereIn('status', [RequestStatus::Approved, RequestStatus::ActionRequired, RequestStatus::Failed])->count();
        $expiring = PolicyEntry::query()->whereBetween('expires_at', [now(), now()->addDay()])->count();

        if (! $this->option('force') && $actionRequired === 0 && $expiring === 0 && $policies->every(fn (array $policy) => $policy['in_sync'])) {
            $this->info('Nothing to report.');

            return self::SUCCESS;
        }

        Notification::route('mail', $address)->notify(new PolicyDigestNotification($policies->all(), $actionRequired, $expiring));
        $this->info("Digest sent to {$address}.");

        return self::SUCCESS;
    }
}
