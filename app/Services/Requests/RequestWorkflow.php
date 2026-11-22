<?php

namespace App\Services\Requests;

use App\Enums\EntrySource;
use App\Enums\RequestStatus;
use App\Jobs\PushAfterRequestChange;
use App\Models\PolicyEntry;
use App\Models\RequestEvent;
use App\Models\User;
use App\Models\WebsiteCheck;
use App\Models\WebsiteRequest;
use App\Services\AuditLogger;
use App\Services\Domains\DomainCategorizer;
use App\Services\Network\NetworkProviderManager;
use App\Services\Network\UnsupportedProviderOperation;
use App\Services\Policy\AccessPlanner;
use App\Services\Policy\ControllerPusher;
use App\Services\Policy\PolicyEngine;
use App\Services\Website\WebsiteChecker;
use App\Support\Reach;
use Carbon\CarbonInterface;
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
     * @param  array{domains: list<string>, access_scope: Reach, category: string, use_case: ?string, requested_from: mixed, requested_until: mixed, schedule?: ?array}  $attributes
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

        // A guide for IT, if website categories are enabled; never blocks submission.
        $categories = rescue(fn () => app(DomainCategorizer::class)->many($websiteRequest->domains), [], false);
        if ($categories !== []) {
            $websiteRequest->forceFill(['domain_categories' => $categories])->save();
        }

        $this->audit->log('website_request.created', $websiteRequest, ['scope' => $websiteRequest->access_scope->value(), 'domains' => $websiteRequest->domains]);
        $this->notifier->submitted($websiteRequest);
        $this->notifier->received($websiteRequest);

        return $websiteRequest;
    }

    /**
     * Approve, place domains in the target allow lists, then try the provider.
     *
     * @param  list<string>|null  $domains  Final hostname set; defaults to the requested domains.
     * @param  array{from: ?CarbonInterface, until: ?CarbonInterface}|null  $window  Access window chosen by IT; defaults to the requested one. A null "until" is permanent.
     */
    public function approve(WebsiteRequest $websiteRequest, User $actor, ?string $note = null, ?array $domains = null, ?Reach $scope = null, ?array $window = null, bool $smart = false): WebsiteRequest
    {
        $this->guard($websiteRequest, RequestStatus::open(), 'Only pending requests can be approved.');
        $domains = array_values(array_unique($domains ?: $websiteRequest->domains));
        $scope ??= $websiteRequest->access_scope;
        $window ??= ['from' => $websiteRequest->requested_from, 'until' => $websiteRequest->requested_until];

        // Smart approval (access requests only): add allow entries only where something needs overriding.
        $plan = $smart && ! $websiteRequest->isBlock() ? app(AccessPlanner::class)->plan($domains, $scope, timed: $window['until'] !== null) : null;

        $websiteRequest = DB::transaction(function () use ($websiteRequest, $actor, $note, $domains, $scope, $window, $plan): WebsiteRequest {
            $websiteRequest->forceFill([
                'status' => RequestStatus::Approved,
                'approved_at' => now(),
                'approved_by_id' => $actor->id,
                'approved_domains' => $domains,
                'applied_scope' => $scope,
                'access_from' => $window['from'],
                'expires_at' => $window['until'],
                'resolution' => $note,
                'access_plan' => $plan,
            ])->save();

            $this->policy->grant($websiteRequest, $websiteRequest->listedDomains(), $scope, $actor);
            $planNote = $plan ? $this->applyPlan($websiteRequest, $plan, $actor) : '';

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

            $changed = $domains !== $websiteRequest->domains || ! $scope->equals($websiteRequest->access_scope) || $websiteRequest->windowChanged();
            $windowNote = $websiteRequest->windowChanged() ? "\n\nAccess window set by IT: {$websiteRequest->windowLabel()}." : '';
            $this->event($websiteRequest, $actor, 'approved', trim($message.$windowNote.$planNote.($note ? "\n\n{$note}" : '')), [
                'domains' => $domains, 'scope' => $scope->value(), 'adjusted' => $changed, 'window' => $websiteRequest->windowLabel(),
            ]);
            $this->audit->log('website_request.approved', $websiteRequest, ['result' => $websiteRequest->status->value, 'domains' => $domains, 'scope' => $scope->value()]);

            return $websiteRequest;
        });

        $opens = $websiteRequest->accessFrom()?->isFuture() ? ' Access opens '.$websiteRequest->accessFrom()->local()->format('j M Y \a\t g:i a').'.' : '';
        $verb = $websiteRequest->isBlock() ? 'Blocked' : 'Allowed';
        $opens = $opens && $websiteRequest->isBlock() ? str_replace('Access opens', 'The block starts', $opens) : $opens;
        $this->notifier->decision($websiteRequest, trim(($note ? "{$note}\n\n" : 'Your request was approved. ')."{$verb}: ".implode(', ', $domains).'.'.$opens
            .($websiteRequest->windowChanged() ? ' IT set the access window to '.$websiteRequest->windowLabel().'.' : '')));

        if ($websiteRequest->status === RequestStatus::ActionRequired && $this->pusher()->automatic()) {
            $this->pushAfter($websiteRequest, $actor, $scope, 'approval');
        }

        return $websiteRequest->refresh();
    }

    /**
     * Mark requests awaiting action as applied once every policy they target
     * is linked to a controller filter that now allows all their hostnames.
     */
    /**
     * Carry out the "remove the block" part of a smart approval and describe
     * the plan for the request history.
     *
     * @param  array<string, array{action: string, reason: string, entries: list<int>, lists: list<string>}>  $plan
     */
    private function applyPlan(WebsiteRequest $websiteRequest, array $plan, User $actor): string
    {
        $lines = [];
        foreach ($plan as $domain => $step) {
            if ($step['action'] === 'unblock' && $step['entries'] !== []) {
                $removed = PolicyEntry::query()->whereKey($step['entries'])->get();
                // The app now owns these blocks, so the next push removes them from the controller
                // instead of treating them as entries made by hand in UniFi.
                foreach (app(AccessPlanner::class)->policiesFor($removed->pluck('id')->all(), $websiteRequest->effectiveScope()) as $policy) {
                    $managed = $policy->managed();
                    $managed['block'] = array_values(array_unique([...$managed['block'], ...$removed->pluck('domain')->all()]));
                    $policy->forceFill(['controller_managed' => $managed])->save();
                }
                foreach ($removed as $entry) {
                    $this->audit->log('policy.entry_removed', $entry->policyList, ['domain' => $entry->domain, 'reason' => "Approved {$websiteRequest->reference}"]);
                }
                PolicyEntry::query()->whereKey($removed->pluck('id'))->delete();
            }
            $lines[] = match ($step['action']) {
                'unblock' => "{$domain}: removed from ".implode(', ', $step['lists']).'. '.$step['reason'],
                'none' => "{$domain}: nothing added. {$step['reason']}",
                default => "{$domain}: allow entry added. {$step['reason']}",
            };
        }

        return "\n\nWhat was changed:\n".implode("\n", $lines);
    }

    public function settlePushed(?User $actor): int
    {
        $settled = 0;
        $rules = $this->policy->rules();
        $waiting = WebsiteRequest::query()->whereIn('status', [RequestStatus::Approved, RequestStatus::ActionRequired, RequestStatus::Failed])->get();

        foreach ($waiting as $websiteRequest) {
            // A policy with no controller filter and no networks filters nothing, so it cannot hold a request up.
            $policies = $this->policy->policiesFor($websiteRequest->effectiveScope())
                ->reject(fn ($policy) => blank($policy->provider_reference) && $policy->networkAssignments()->doesntExist())->values();
            if ($policies->isEmpty() || $policies->contains(fn ($policy) => blank($policy->provider_reference) || $policy->snapshot_at === null)) {
                continue;
            }

            // A recurring request that is off right now has nothing to see in the filters yet; its
            // entries are in place and the scheduler pushes each time the schedule switches on.
            if (($schedule = $websiteRequest->recurrence()) && ! $schedule->isActive(now())) {
                $websiteRequest->forceFill(['status' => RequestStatus::Active, 'resolved_at' => now()])->save();
                $this->event($websiteRequest, $actor, 'applied', 'Scheduled: '.$schedule->label().'. Each occurrence is pushed to the controller automatically.');
                $settled++;

                continue;
            }
            // Listed hostnames must be in the filter's list; hostnames a smart approval unblocked (or left alone) must not be blocked.
            $listed = $websiteRequest->listedDomains();
            $applied = $policies->every(fn ($policy) => collect($websiteRequest->effectiveDomains())->every(function (string $domain) use ($policy, $listed, $rules, $websiteRequest): bool {
                if (in_array($domain, $listed, true)) {
                    return collect(($websiteRequest->isBlock() ? $policy->current_blocklist : $policy->current_allowlist) ?? [])->contains(fn (string $entry) => $rules->covers($entry, $domain));
                }

                return ! collect($policy->current_blocklist ?? [])->contains(fn (string $entry) => $rules->covers($entry, $domain));
            }));
            if (! $applied) {
                continue;
            }

            $names = $policies->map(fn ($policy) => $policy->provider_name ?: $policy->name)->join(', ');
            $websiteRequest->forceFill(['status' => RequestStatus::Active, 'resolved_at' => now()])->save();
            $this->event($websiteRequest, $actor, 'applied', "Applied automatically to the controller content filters: {$names}.");
            $this->audit->log('website_request.applied', $websiteRequest, ['automatic' => true]);
            $this->notifier->decision($websiteRequest, $websiteRequest->isBlock() ? 'The block you asked for is now in place on the network.' : 'Your approved access is now live on the network.');
            $settled++;
        }

        return $settled;
    }

    /** Push the affected filters in the background, so the page answers straight away. */
    private function pushAfter(WebsiteRequest $websiteRequest, ?User $actor, Reach $scope, string $trigger): void
    {
        PushAfterRequestChange::dispatch($websiteRequest->id, $actor?->id, $scope->value(), $trigger)->afterCommit();
    }

    /** Push now (from the background job) and note a failure on the request. */
    public function pushNow(WebsiteRequest $websiteRequest, ?User $actor, Reach $scope, string $trigger): void
    {
        try {
            $results = $this->pusher()->pushAll($actor, $trigger, $scope);
        } catch (Throwable $exception) {
            report($exception);

            return;
        }

        $failed = $results->reject(fn (array $result) => $result['ok']);
        if ($failed->isNotEmpty()) {
            $this->event($websiteRequest, $actor, 'push_failed', 'Automatic push did not complete: '.$failed->pluck('message')->join(' ').' It is retried automatically within 5 minutes, or IT can push from the policy page.', internal: true);
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

    /**
     * Change when a granted request's access starts and ends. A null
     * "until" makes it permanent. The request's own policy entries move
     * with it and the change is pushed when automatic pushing is on.
     */
    public function changeWindow(WebsiteRequest $websiteRequest, User $actor, ?CarbonInterface $from, ?CarbonInterface $until, ?string $note = null): WebsiteRequest
    {
        $this->guard($websiteRequest, RequestStatus::granted(), 'Only approved or active requests can have their access window changed.');
        $before = $websiteRequest->windowLabel();

        DB::transaction(function () use ($websiteRequest, $from, $until): void {
            $websiteRequest->forceFill(['access_from' => $from, 'expires_at' => $until, 'expiry_reminded_at' => null])->save();
            $websiteRequest->policyEntries()->where('source', EntrySource::Request)->update(['starts_at' => $from, 'expires_at' => $until]);
        });

        $after = $websiteRequest->windowLabel();
        $this->event($websiteRequest, $actor, 'window_changed', "Access window changed from {$before} to {$after}.".($note ? "\n\n{$note}" : ''), ['before' => $before, 'after' => $after]);
        $this->audit->log('website_request.window_changed', $websiteRequest, ['before' => $before, 'after' => $after]);
        $this->notifier->decision($websiteRequest, "IT changed when your access applies. It is now: {$after}.".($note ? "\n\n{$note}" : ''));

        if ($this->pusher()->automatic()) {
            $this->pushAfter($websiteRequest, $actor, $websiteRequest->effectiveScope(), 'window_change');
        }

        return $websiteRequest->refresh();
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
        $this->notifier->expired($websiteRequest);
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
