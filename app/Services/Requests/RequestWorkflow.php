<?php

namespace App\Services\Requests;

use App\Enums\RequestStatus;
use App\Models\RequestEvent;
use App\Models\User;
use App\Models\WebsiteRequest;
use App\Notifications\RequestDecisionNotification;
use App\Services\AuditLogger;
use App\Services\Network\NetworkProviderManager;
use App\Services\Network\UnsupportedProviderOperation;
use Illuminate\Support\Facades\DB;
use Throwable;

class RequestWorkflow
{
    public function __construct(
        private readonly NetworkProviderManager $providers,
        private readonly AuditLogger $audit,
    ) {}

    public function approve(WebsiteRequest $websiteRequest, User $actor, ?string $note = null): WebsiteRequest
    {
        return DB::transaction(function () use ($websiteRequest, $actor, $note): WebsiteRequest {
            $websiteRequest->forceFill([
                'status' => RequestStatus::Approved,
                'approved_at' => now(),
                'expires_at' => $websiteRequest->requested_until,
                'resolution' => $note,
            ])->save();

            try {
                $integration = $this->providers->integration();
                if (! $integration || $integration->mode !== 'active') {
                    throw new UnsupportedProviderOperation('The network integration is in read-only mode. IT must complete the UniFi change manually.');
                }

                $provider = $this->providers->current();
                if (! ($provider->capabilities()['domain_allowlist'] ?? false)) {
                    throw new UnsupportedProviderOperation('This provider does not advertise a supported domain allowlist operation. IT must complete the change manually.');
                }

                $state = $provider->apply($websiteRequest);
                $websiteRequest->forceFill(['status' => RequestStatus::Active, 'provider_state' => $state, 'resolved_at' => now()])->save();
                $message = 'Approved and applied to the network provider.';
            } catch (UnsupportedProviderOperation $exception) {
                $websiteRequest->forceFill(['status' => RequestStatus::ActionRequired])->save();
                $message = $exception->getMessage();
            } catch (Throwable $exception) {
                report($exception);
                $websiteRequest->forceFill(['status' => RequestStatus::Failed])->save();
                $message = 'Approved, but the network provider action failed. IT intervention is required.';
            }

            $this->event($websiteRequest, $actor, 'approved', $message);
            $this->audit->log('website_request.approved', $websiteRequest, ['result' => $websiteRequest->status->value]);
            $websiteRequest->requester->notify(new RequestDecisionNotification($websiteRequest, $message));

            return $websiteRequest->refresh();
        });
    }

    public function reject(WebsiteRequest $websiteRequest, User $actor, string $reason): WebsiteRequest
    {
        $websiteRequest->forceFill(['status' => RequestStatus::Rejected, 'resolution' => $reason, 'resolved_at' => now()])->save();
        $this->event($websiteRequest, $actor, 'rejected', $reason);
        $this->audit->log('website_request.rejected', $websiteRequest);
        $websiteRequest->requester->notify(new RequestDecisionNotification($websiteRequest, $reason));

        return $websiteRequest;
    }

    public function requestInformation(WebsiteRequest $websiteRequest, User $actor, string $question): WebsiteRequest
    {
        $websiteRequest->forceFill(['status' => RequestStatus::NeedsInformation])->save();
        $this->event($websiteRequest, $actor, 'information_requested', $question);
        $websiteRequest->requester->notify(new RequestDecisionNotification($websiteRequest, $question));

        return $websiteRequest;
    }

    public function assign(WebsiteRequest $websiteRequest, User $actor, User $assignee): WebsiteRequest
    {
        $websiteRequest->forceFill(['assigned_to_id' => $assignee->id])->save();
        $this->event($websiteRequest, $actor, 'assigned', "Assigned to {$assignee->name}.");

        return $websiteRequest;
    }

    public function markApplied(WebsiteRequest $websiteRequest, User $actor, string $note): WebsiteRequest
    {
        $websiteRequest->forceFill(['status' => RequestStatus::Active, 'resolution' => $note, 'resolved_at' => now()])->save();
        $this->event($websiteRequest, $actor, 'manually_applied', $note);
        $websiteRequest->requester->notify(new RequestDecisionNotification($websiteRequest, $note));

        return $websiteRequest;
    }

    private function event(WebsiteRequest $request, User $actor, string $type, string $message): void
    {
        RequestEvent::query()->create(['website_request_id' => $request->id, 'actor_id' => $actor->id, 'type' => $type, 'message' => $message]);
    }
}
