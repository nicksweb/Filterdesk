<?php

namespace App\Console\Commands;

use App\Enums\RequestStatus;
use App\Models\WebsiteRequest;
use App\Services\Requests\RequestNotifier;
use Illuminate\Console\Command;

/**
 * Tells requesters their temporary access ends within a day. Short windows
 * (a lesson, an exam) are skipped: the requester already knows the end time.
 */
class RemindExpiringRequests extends Command
{
    protected $signature = 'requests:remind';

    protected $description = 'Email requesters whose temporary access ends within 24 hours';

    public function handle(RequestNotifier $notifier): int
    {
        WebsiteRequest::query()->with('requester')
            ->whereIn('status', RequestStatus::granted())
            ->whereNull('expiry_reminded_at')
            ->whereBetween('expires_at', [now(), now()->addDay()])
            ->each(function (WebsiteRequest $request) use ($notifier): void {
                $started = $request->access_from ?? $request->approved_at;
                if ($started && $started->diffInHours($request->expires_at) > 24) {
                    $notifier->expiringSoon($request);
                }
                $request->forceFill(['expiry_reminded_at' => now()])->save();
            });

        return self::SUCCESS;
    }
}
