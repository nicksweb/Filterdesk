<?php

namespace App\Services\Requests;

use App\Enums\AccessScope;
use App\Enums\RequestStatus;
use App\Models\RequestEvent;
use App\Models\User;
use App\Models\WebsiteCheck;
use App\Models\WebsiteRequest;
use App\Services\AuditLogger;
use App\Services\Network\NetworkProviderManager;
use App\Services\Network\UnsupportedProviderOperation;
use App\Services\Policy\ControllerPusher;
use App\Services\Policy\PolicyEngine;
use App\Services\Website\WebsiteChecker;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Every state change to a website request goes through here so the timeline,
 * audit log, policy lists and notifications stay consistent.
 */
class RequestWorkflow
{
    public function __construct(
        private readonly NetworkProviderManager $providers,
        private readonly AuditLogger $audit,
        private readonly PolicyEngine $policy,
        private readonly RequestNotifier $notifier,
        private readonly WebsiteChecker $checker,
    ) {}

    /**
     * @param  array{domains: list<string>, access_scope: AccessScope, category: string, use_case: string, requested_from: mixed, requested_until: mixed}  $attributes
     */
    public function submit(User $requester, array $attributes): WebsiteRequest
    {
        $websiteRequest = DB::transaction(function () use ($requester, $attributes): WebsiteRequest {
            $websiteRequest = WebsiteRequest::query()->create($attributes + [
                'reference' => 'PENDING-'.str()->uuid(),
                'requester_id' => $requester->id,
                'standards_confirmed' => true,
                'status' => RequestStatus::Pending,
                'related_domains' => $this->relatedFromRecentChecks($requester, $attributes['domains']),
            ]);
            $websiteRequest->forceFill(['reference' => sprintf('WEB-%s-%05d', now()->format('Y'), $websiteRequest->id)])->save();
            $this->event($websiteRequest, $requester, 'submitted', 'Website access request submitted.');

            return $websiteRequest;
        });

        $this->audit->log('website_request.created', $websiteRequest, ['scope' => $websiteRequest->access_scope->value, 'domains' => $websiteRequest->domains]);
        $this->notifier->submitted($websiteRequest);

        return $websiteRequest;
    }

    /**
     * Approve, place domains in the target allow lists, then try the provider.
     *
     * @param  list<string>|null  $domains  Final hostname set; defaults to the requested domains.
     */
    public function approve(WebsiteRequest $websiteRequest, User $actor, ?string $note = null, ?array $domains = null, ?AccessScope $scope = null): WebsiteRequest
    {
        $this->guard($websiteRequest, RequestStatus::open(), 'Only pending requests can be approved.');
        $domains = array_values(array_unique($domains ?: $websiteRequest->domains));
        $scope ??= $websiteRequest->access_scope;

        $websiteRequest = DB::transaction(function () use ($websiteRequest, $actor, $note, $domains, $scope): WebsiteRequest {
            $websiteRequest->forceFill([
                'status' => RequestStatus::Approved,
                'approved_at' => now(),
                'approved_by_id' => $actor->id,
                'approved_domains' => $domains,
                'applied_scope' => $scope,
                'expires_at' => $websiteRequest->requested_until,
                'resolution' => $note,
            ])->save();

            $this->policy->grant($websiteRequest, $domains, $scope, $actor);

            try {
                $integration = $this->providers->integration();
                if ($this->pusher()->automatic()) {
                    throw new UnsupportedProviderOperation('Approved and added to the policy lists. Pushing to the linked controller filters.');
                }
                if (! $integration || $integration->mode !== 'active') {
                    throw new UnsupportedProviderOperation('Approved and added to the policy lists. IT will apply the change in the network controller.');
                }

                $provider = $this->providers->current();
                if (! ($provider->capabilities()['domain_allowlist'] ?? false)) {
                    throw new UnsupportedProviderOperation('Approved and added to the policy lists. This provider cannot apply domain lists automatically, so IT will complete the change.');
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

            $changed = $domains !== $websiteRequest->domains || $scope !== $websiteRequest->access_scope;
            $this->event($websiteRequest, $actor, 'approved', trim($message.($note ? "\n\n{$note}" : '')), [
                'domains' => $domains, 'scope' => $scope->value, 'adjusted' => $changed,
            ]);
            $this->audit->log('website_request.approved', $websiteRequest, ['result' => $websiteRequest->status->value, 'domains' => $domains, 'scope' => $scope->value]);

            return $websiteRequest;
        });

        $this->notifier->decision($websiteRequest, $note ? "{$note}\n\nAllowed: ".implode(', ', $domains) : 'Your request was approved. Allowed: '.implode(', ', $domains));

        if ($websiteRequest->status === RequestStatus::ActionRequired && $this->pusher()->automatic()) {
            $this->pushAfter($websiteRequest, $actor, $scope, 'approval');
        }

        return $websiteRequest->refresh();
    }

    /**
     * Mark requests awaiting action as applied once every policy they target
     * is linked to a controller filter that now allows all their hostnames.
     */
    public function settlePushed(?User $actor): int
    {
        $settled = 0;
        $rules = $this->policy->rules();
        $waiting = WebsiteRequest::query()->whereIn('status', [RequestStatus::Approved, RequestStatus::ActionRequired, RequestStatus::Failed])->get();

        foreach ($waiting as $websiteRequest) {
            $policies = $this->policy->policiesFor($websiteRequest->effectiveScope());
            if ($policies->isEmpty() || $policies->contains(fn ($policy) => blank($policy->provider_reference) || $policy->snapshot_at === null)) {
                continue;
            }

            $applied = $policies->every(fn ($policy) => collect($websiteRequest->effectiveDomains())->every(
                fn (string $domain) => collect($policy->current_allowlist ?? [])->contains(fn (string $entry) => $rules->covers($entry, $domain))
            ));
            if (! $applied) {
                continue;
            }

            $names = $policies->map(fn ($policy) => $policy->provider_name ?: $policy->name)->join(', ');
            $websiteRequest->forceFill(['status' => RequestStatus::Active, 'resolved_at' => now()])->save();
            $this->event($websiteRequest, $actor, 'applied', "Applied automatically to the controller content filters: {$names}.");
            $this->audit->log('website_request.applied', $websiteRequest, ['automatic' => true]);
            $this->notifier->decision($websiteRequest, 'Your approved access is now live on the network.');
            $settled++;
        }

        return $settled;
    }

    private function pushAfter(WebsiteRequest $websiteRequest, ?User $actor, AccessScope $scope, string $trigger): void
    {
        try {
            $results = $this->pusher()->pushAll($actor, $trigger, $scope);
        } catch (Throwable $exception) {
            report($exception);

            return;
        }

        $failed = $results->reject(fn (array $result) => $result['ok']);
        if ($failed->isNotEmpty()) {
            $this->event($websiteRequest, $actor, 'push_failed', 'Automatic push did not complete: '.$failed->pluck('message')->join(' ').' IT will apply the change.', internal: true);
        }
    }

    private function pusher(): ControllerPusher
    {
        return app(ControllerPusher::class);
    }

    public function reject(WebsiteRequest $websiteRequest, User $actor, string $reason): WebsiteRequest
    {
        $this->guard($websiteRequest, RequestStatus::open(), 'Only pending requests can be rejected.');
        $websiteRequest->forceFill(['status' => RequestStatus::Rejected, 'resolution' => $reason, 'resolved_at' => now()])->save();
        $this->event($websiteRequest, $actor, 'rejected', $reason);
        $this->audit->log('website_request.rejected', $websiteRequest);
        $this->notifier->decision($websiteRequest, $reason);

        return $websiteRequest;
    }

    public function requestInformation(WebsiteRequest $websiteRequest, User $actor, string $question): WebsiteRequest
    {
        $this->guard($websiteRequest, RequestStatus::open(), 'Information can only be requested on a pending request.');
        $websiteRequest->forceFill(['status' => RequestStatus::NeedsInformation])->save();
        $this->event($websiteRequest, $actor, 'information_requested', $question);
        $this->notifier->decision($websiteRequest, $question);

        return $websiteRequest;
    }

    public function reply(WebsiteRequest $websiteRequest, User $requester, string $message): WebsiteRequest
    {
        $this->guard($websiteRequest, [RequestStatus::NeedsInformation], 'IT has not asked for more information.');
        $websiteRequest->forceFill(['status' => RequestStatus::Pending])->save();
        $this->event($websiteRequest, $requester, 'information_provided', $message);
        $this->notifier->replied($websiteRequest, $message);

        return $websiteRequest;
    }

    public function assign(WebsiteRequest $websiteRequest, User $actor, ?User $assignee): WebsiteRequest
    {
        if ($assignee && (! $assignee->isIt() || ! $assignee->enabled)) {
            throw new InvalidRequestTransition('Requests can only be assigned to enabled IT staff.');
        }

        $websiteRequest->forceFill(['assigned_to_id' => $assignee?->id, 'assigned_at' => $assignee ? now() : null])->save();
        $this->event($websiteRequest, $actor, 'assigned', $assignee ? ($assignee->is($actor) ? "{$actor->name} took this request." : "Assigned to {$assignee->name}.") : 'Unassigned.', internal: true);
        $this->audit->log('website_request.assigned', $websiteRequest, ['assignee_id' => $assignee?->id]);

        if ($assignee) {
            $this->notifier->assigned($websiteRequest->setRelation('assignedTo', $assignee), $assignee, $actor);
        }

        return $websiteRequest;
    }

    public function markApplied(WebsiteRequest $websiteRequest, User $actor, ?string $note = null): WebsiteRequest
    {
        $this->guard($websiteRequest, [RequestStatus::Approved, RequestStatus::ActionRequired, RequestStatus::Failed], 'Only approved requests awaiting action can be marked applied.');
        $note = filled($note) ? $note : 'The change has been applied in the network controller.';
        $websiteRequest->forceFill(['status' => RequestStatus::Active, 'resolved_at' => now()])->save();
        $this->event($websiteRequest, $actor, 'manually_applied', $note);
        $this->audit->log('website_request.applied', $websiteRequest);
        $this->notifier->decision($websiteRequest, $note);

        return $websiteRequest;
    }

    public function revoke(WebsiteRequest $websiteRequest, User $actor, string $reason): WebsiteRequest
    {
        $this->guard($websiteRequest, RequestStatus::granted(), 'Only granted requests can be revoked.');

        $automatic = $this->pusher()->automatic();
        $websiteRequest = DB::transaction(function () use ($websiteRequest, $actor, $reason, $automatic): WebsiteRequest {
            try {
                $this->providers->current()->revoke($websiteRequest);
            } catch (Throwable $exception) {
                report($exception);
            }

            $removed = $this->policy->withdraw($websiteRequest);
            $websiteRequest->forceFill(['status' => RequestStatus::Revoked, 'resolution' => $reason, 'resolved_at' => now()])->save();
            $this->event($websiteRequest, $actor, 'revoked', "{$reason}\n\n{$removed} policy entr".($removed === 1 ? 'y' : 'ies').' removed. '
                .($automatic ? 'Removing them from the linked controller filters.' : 'Remove them from the network controller at the next reconciliation.'));
            $this->audit->log('website_request.revoked', $websiteRequest, ['entries_removed' => $removed]);
            $this->notifier->decision($websiteRequest, $reason);

            return $websiteRequest;
        });

        if ($automatic) {
            $this->pushAfter($websiteRequest, $actor, $websiteRequest->effectiveScope(), 'revocation');
        }

        return $websiteRequest;
    }

    public function note(WebsiteRequest $websiteRequest, User $actor, string $note): WebsiteRequest
    {
        $this->event($websiteRequest, $actor, 'note', $note, internal: true);

        return $websiteRequest;
    }

    /** Called by the scheduler when a temporary window ends. */
    public function expire(WebsiteRequest $websiteRequest): void
    {
        $automated = $websiteRequest->status === RequestStatus::Active && isset($websiteRequest->provider_state['resource_id']);

        try {
            $this->providers->current()->revoke($websiteRequest);
            $message = match (true) {
                $automated => 'Temporary access expired and was removed from the network provider.',
                $this->pusher()->automatic() => 'Temporary access expired. It has left the desired policy lists and will be removed from the linked controller filters.',
                default => 'Temporary access expired. It has left the desired policy lists; remove it from the network controller at the next reconciliation.',
            };
        } catch (Throwable $exception) {
            report($exception);
            $message = 'Temporary access expired; IT must remove it from the network controller manually.';
        }

        $websiteRequest->forceFill(['status' => RequestStatus::Expired])->save();
        $this->event($websiteRequest, null, 'expired', $message);
        $this->audit->log('website_request.expired', $websiteRequest);
    }

    /**
     * @param  list<RequestStatus>  $allowed
     */
    private function guard(WebsiteRequest $websiteRequest, array $allowed, string $message): void
    {
        if (! in_array($websiteRequest->status, $allowed, true)) {
            throw new InvalidRequestTransition($message);
        }
    }

    /** @param array<string, mixed> $metadata */
    private function event(WebsiteRequest $request, ?User $actor, string $type, string $message, array $metadata = [], bool $internal = false): void
    {
        RequestEvent::query()->create([
            'website_request_id' => $request->id, 'actor_id' => $actor?->id, 'type' => $type,
            'message' => $message, 'metadata' => $metadata ?: null, 'internal' => $internal,
        ]);
    }

    /**
     * Hosts observed by the requester's checks of these domains in the last day.
     *
     * @param  list<string>  $domains
     * @return list<array{host: string, failed_on: list<string>}>
     */
    private function relatedFromRecentChecks(User $requester, array $domains): array
    {
        $hosts = [];
        WebsiteCheck::query()->where('user_id', $requester->id)->whereIn('hostname', $domains)
            ->where('created_at', '>=', now()->subDay())->latest()->limit(25)->get()
            ->each(function (WebsiteCheck $check) use (&$hosts): void {
                foreach ($this->checker->relatedHosts($check->results ?? []) as $host) {
                    $hosts[$host['host']] ??= $host;
                    $hosts[$host['host']]['failed_on'] = array_values(array_unique([...$hosts[$host['host']]['failed_on'], ...$host['failed_on']]));
                }
            });

        return array_values(array_diff_key($hosts, array_flip($domains)));
    }
}
