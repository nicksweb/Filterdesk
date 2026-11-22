<?php

namespace App\Console\Commands;

use App\Enums\RequestStatus;
use App\Models\WebsiteRequest;
use App\Services\Requests\RequestWorkflow;
use Illuminate\Console\Command;

class ExpireWebsiteRequests extends Command
{
    protected $signature = 'requests:expire';

    protected $description = 'Expire temporary website access whose window has ended';

    public function handle(RequestWorkflow $workflow): int
    {
        WebsiteRequest::query()
            ->whereIn('status', RequestStatus::granted())
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->each(fn (WebsiteRequest $websiteRequest) => $workflow->expire($websiteRequest));

        return self::SUCCESS;
    }
}
