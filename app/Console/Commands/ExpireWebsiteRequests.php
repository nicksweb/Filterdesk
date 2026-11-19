<?php

namespace App\Console\Commands;

use App\Enums\RequestStatus;
use App\Models\RequestEvent;
use App\Models\WebsiteRequest;
use App\Services\Network\NetworkProviderManager;
use Illuminate\Console\Command;
use Throwable;

class ExpireWebsiteRequests extends Command
{
    protected $signature = 'requests:expire';

    protected $description = 'Revoke and expire temporary website access requests';

    public function handle(NetworkProviderManager $providers): int
    {
        WebsiteRequest::query()
            ->where('status', RequestStatus::Active)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->each(function (WebsiteRequest $websiteRequest) use ($providers): void {
                try {
                    $providers->current()->revoke($websiteRequest);
                    $websiteRequest->forceFill(['status' => RequestStatus::Expired])->save();
                    $message = 'Temporary access expired and was removed.';
                } catch (Throwable $exception) {
                    report($exception);
                    $websiteRequest->forceFill(['status' => RequestStatus::ActionRequired])->save();
                    $message = 'Temporary access expired; IT must remove it manually.';
                }

                RequestEvent::query()->create(['website_request_id' => $websiteRequest->id, 'type' => 'expired', 'message' => $message]);
            });

        return self::SUCCESS;
    }
}
