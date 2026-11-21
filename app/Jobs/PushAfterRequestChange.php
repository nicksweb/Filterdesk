<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\WebsiteRequest;
use App\Services\Requests\RequestWorkflow;
use App\Support\Reach;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pushes the filters a request change affects, in the background. Several
 * filters through the cloud connector, each followed by the gateway's DNS
 * reload, can take a minute; the page that approved or revoked the request
 * should not wait for that.
 */
class PushAfterRequestChange implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** One attempt: a failure is recorded on the request, and the scheduled push retries within 5 minutes. */
    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(
        public readonly int $websiteRequestId,
        public readonly ?int $actorId,
        public readonly string $scope,
        public readonly string $trigger,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->websiteRequestId}:{$this->trigger}";
    }

    public function handle(RequestWorkflow $workflow): void
    {
        $request = WebsiteRequest::query()->find($this->websiteRequestId);
        if ($request) {
            $workflow->pushNow($request, $this->actorId ? User::query()->find($this->actorId) : null, Reach::from($this->scope), $this->trigger);
        }
    }
}
