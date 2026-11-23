<?php

namespace App\Services\Policy;

use App\Models\FilterPolicy;
use App\Models\NetworkAssignment;
use App\Models\User;
use App\Notifications\FilterSyncAlertNotification;
use App\Services\AuditLogger;
use App\Services\Network\ProviderRequestFailed;
use App\Services\Requests\RequestNotifier;
use App\Services\SettingsRepository;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Baseline filtering: no network is left without a content filter by
 * accident. A network with no filter in the controller is added to the
 * baseline policy's filter (the Students policy unless another is chosen),
 * so it gets at least student-level filtering, unless an admin has allowed
 * it to stay unfiltered and said why.
 *
 * When the guard first runs, networks that are already unfiltered are not
 * touched: they are listed for an admin to decide. Networks that appear, or
 * lose their filter, after that are filtered straight away (in Active mode).
 */
class BaselineGuard
{
    public const STARTED_KEY = 'policy.baseline_started_at';

    public const POLICY_KEY = 'policy.baseline_policy_id';

    public const FOUND_AT_START = 'Already unfiltered when baseline filtering started';

    /** Ids of controller filters known to be paused (disabled in UniFi), so IT is told once. */
    public const PAUSED_KEY = 'policy.paused_filters';

    public function __construct(
        private readonly ControllerSync $sync,
        private readonly ControllerPusher $pusher,
        private readonly SettingsRepository $settings,
        private readonly AuditLogger $audit,
        private readonly RequestNotifier $notifier,
    ) {}

    /** The policy whose filter unfiltered networks join: the chosen one, else the first linked Students policy. */
    public function policy(): ?FilterPolicy
    {
        $linked = FilterPolicy::query()->main()->where('enabled', true)->whereNotNull('provider_reference')->ordered();
        $chosen = $this->settings->get(self::POLICY_KEY);

        if ($chosen && ($policy = (clone $linked)->whereKey($chosen)->first())) {
            return $policy;
        }
        // Prefer an always-on Students filter: a school-hours one filters nothing out of hours.
        $state = $this->sync->state();
        $schedules = collect($state['filters'] ?? [])->pluck('schedule', 'id');

        return (clone $linked)->where('audience', 'student')->where('role', 'group')->get()
            ->sortBy(fn (FilterPolicy $policy) => ($schedules[$policy->provider_reference] ?? 'ALWAYS') === 'ALWAYS' ? 0 : 1)->first();
    }

    /**
     * Every network with no working content filter, with what will happen
     * to it: allowed (an admin allowed it), paused (its filters are all
     * paused in UniFi; flagged, never changed automatically, since pausing
     * is usually deliberate), decide (it was unfiltered when the guard
     * started) or enforce (it will be filtered).
     *
     * @return Collection<int, array{network: NetworkAssignment, report: array<string, mixed>, paused: list<string>, state: string}>
     */
    public function unfiltered(?array $state = null): Collection
    {
        $state ??= $this->sync->state();
        if ($state['error']) {
            return collect();
        }
        $assignments = NetworkAssignment::query()->with('unfilteredReviewedBy')->get()->keyBy('provider_network_id');

        return $this->sync->networkReport($state)
            ->filter(fn (array $network) => $assignments->has($network['id']) && collect($network['attached'])->doesntContain(fn (array $filter) => $filter['enabled'] ?? true))
            ->map(function (array $network) use ($assignments): array {
                $assignment = $assignments->get($network['id']);

                return [
                    'network' => $assignment, 'report' => $network,
                    'paused' => collect($network['attached'])->pluck('name')->values()->all(),
                    'state' => match (true) {
                        $assignment->allowedUnfiltered() => 'allowed',
                        $network['attached'] !== [] => 'paused',
                        $assignment->needsBaselineDecision() => 'decide',
                        default => 'enforce',
                    },
                ];
            })->values();
    }

    /**
     * Mark networks that are unfiltered on the first run as "decide", then
     * add every other unfiltered network to the baseline filter (Active mode
     * only; otherwise they are reported).
     *
     * @return array{ok: bool, filtered: list<string>, message: string}
     */
    public function enforce(?User $actor = null, ?array $only = null): array
    {
        $state = $this->sync->state(true);
        if ($state['error']) {
            return ['ok' => false, 'filtered' => [], 'message' => $state['error']];
        }
        $unfiltered = $this->unfiltered($state);
        $pausedNotice = $this->notePausedFilters($state, $actor);

        if (! $this->settings->get(self::STARTED_KEY)) {
            foreach ($unfiltered as $item) {
                if ($item['state'] === 'enforce') {
                    $item['network']->update(['unfiltered_reason' => self::FOUND_AT_START, 'unfiltered_reviewed_at' => null]);
                }
            }
            $this->settings->set(self::STARTED_KEY, now()->toIso8601String());
            $unfiltered = $this->unfiltered($state);
        }

        $due = $unfiltered->filter(fn (array $item) => $only !== null ? in_array($item['network']->provider_network_id, $only, true) && $item['state'] !== 'allowed' : $item['state'] === 'enforce');
        if ($due->isEmpty()) {
            return ['ok' => $pausedNotice === null, 'filtered' => [], 'message' => $pausedNotice ?? 'Every network has a content filter or is allowed to stay unfiltered.'];
        }
        $names = $due->map(fn (array $item) => $item['network']->name)->values()->all();
        $policy = $this->policy();
        $baselineFilter = $policy ? collect($state['filters'])->firstWhere('id', $policy->provider_reference) : null;
        if ($baselineFilter && ! ($baselineFilter['enabled'] ?? true)) {
            return ['ok' => false, 'filtered' => [], 'message' => "{$baselineFilter['name']} ({$policy->name}'s filter) is paused in UniFi, so adding networks to it would not filter them. Turn it back on in UniFi, or choose another baseline policy."];
        }
        if (! $policy) {
            return ['ok' => false, 'filtered' => [], 'message' => 'No Students policy is linked to a controller content filter, so '.implode(', ', $names).' cannot be given baseline filtering. Link one in Guided setup.'];
        }
        if (! $this->pusher->enabled() || ! $this->pusher->writer()) {
            return ['ok' => false, 'filtered' => [], 'message' => implode(', ', $names).' '.(count($names) === 1 ? 'has' : 'have').' no content filter. Filterdesk adds them to '.$policy->name.' once the network provider is in Active mode, or add them in UniFi.'];
        }

        try {
            $this->pusher->writer()->attachNetworksToContentFilter($policy->provider_reference, $due->map(fn (array $item) => $item['network']->provider_network_id)->values()->all());
        } catch (ProviderRequestFailed $exception) {
            return ['ok' => false, 'filtered' => [], 'message' => $exception->getMessage()];
        } catch (Throwable $exception) {
            report($exception);

            return ['ok' => false, 'filtered' => [], 'message' => 'The controller could not be reached. Nothing was changed.'];
        }

        foreach ($due as $item) {
            $item['network']->update(['filter_policy_id' => $policy->id, 'unfiltered_reason' => null, 'unfiltered_reviewed_at' => null, 'unfiltered_reviewed_by_id' => null]);
        }
        $this->sync->forget();
        $this->audit->log('policy.baseline_filtered', $policy, ['networks' => $names, 'by' => $actor ? 'admin' : 'guard']);
        $summary = implode(', ', $names).' had no content filter and now '.(count($names) === 1 ? 'has' : 'have')." {$policy->name} filtering.";
        if (! $actor) {
            $this->notifier->filterSyncAlert(new FilterSyncAlertNotification([['policy' => $policy->name, 'slug' => $policy->slug, 'status' => 'baseline', 'summary' => $summary]]));
        }

        return ['ok' => true, 'filtered' => $names, 'message' => $summary];
    }

    /**
     * Tell IT once when a content filter is paused (disabled) in UniFi:
     * every network it covers is then unfiltered by it. Returns a summary
     * of the paused filters, or null when none is paused.
     */
    private function notePausedFilters(array $state, ?User $actor): ?string
    {
        $paused = collect($state['filters'])->reject(fn (array $filter) => $filter['enabled'] ?? true)->keyBy('id');
        $known = (array) $this->settings->get(self::PAUSED_KEY, []);
        $new = $paused->except($known);
        if ($paused->keys()->sort()->values()->all() !== collect($known)->sort()->values()->all()) {
            $this->settings->set(self::PAUSED_KEY, $paused->keys()->values()->all());
        }
        if ($new->isNotEmpty()) {
            $this->audit->log('policy.filter_paused', null, ['filters' => $new->pluck('name')->values()->all()]);
            if (! $actor) {
                $this->notifier->filterSyncAlert(new FilterSyncAlertNotification($new->map(fn (array $filter) => [
                    'policy' => $filter['name'], 'slug' => '', 'status' => 'paused',
                    'summary' => 'It is paused in UniFi, so it filters nothing on '.(count($filter['network_ids']) === 1 ? 'its network' : 'its '.count($filter['network_ids']).' networks').'. Turn it back on in UniFi, or decide under Networks & filters → Baseline filtering.',
                ])->values()->all()));
            }
        }

        return $paused->isEmpty() ? null : $paused->pluck('name')->join(', ').' '.($paused->count() === 1 ? 'is' : 'are').' paused in UniFi; '.($paused->count() === 1 ? 'its' : 'their').' networks are unfiltered until turned back on.';
    }

    /**
     * Add networks to the baseline (Students) filter on request, whatever
     * they have now: protection for a device network, or a user network
     * with only site-wide filtering.
     *
     * @param  list<string>  $networkIds
     * @return array{ok: bool, message: string}
     */
    public function protect(array $networkIds, User $actor): array
    {
        $policy = $this->policy();
        if (! $policy) {
            return ['ok' => false, 'message' => 'No Students policy is linked to a UniFi filter yet.'];
        }
        if (! $this->pusher->enabled() || ! $this->pusher->writer()) {
            return ['ok' => false, 'message' => "Adding {$policy->name}'s filter needs the network provider in Active mode. Or add the network to {$policy->provider_name} in UniFi."];
        }
        $networks = NetworkAssignment::query()->whereIn('provider_network_id', $networkIds)->get();
        try {
            $this->pusher->writer()->attachNetworksToContentFilter($policy->provider_reference, $networks->pluck('provider_network_id')->all());
        } catch (ProviderRequestFailed $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }
        NetworkAssignment::query()->whereIn('id', $networks->pluck('id'))->whereNull('filter_policy_id')->update(['filter_policy_id' => $policy->id]);
        $this->sync->forget();
        $this->audit->log('policy.baseline_protected', $policy, ['networks' => $networks->pluck('name')->all()]);

        return ['ok' => true, 'message' => $networks->pluck('name')->join(', ').' now '.($networks->count() === 1 ? 'has' : 'have')." {$policy->name} filtering in UniFi."];
    }

    /** Let a network stay unfiltered, or take that back. */
    public function allow(NetworkAssignment $network, ?string $reason, User $actor): void
    {
        $network->update(filled($reason)
            ? ['unfiltered_reason' => $reason, 'unfiltered_reviewed_at' => now(), 'unfiltered_reviewed_by_id' => $actor->id]
            : ['unfiltered_reason' => null, 'unfiltered_reviewed_at' => null, 'unfiltered_reviewed_by_id' => null]);
        $this->audit->log(filled($reason) ? 'policy.baseline_exempted' : 'policy.baseline_exemption_removed', null, ['network' => $network->name, 'reason' => $reason]);
    }
}
