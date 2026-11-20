<?php

namespace App\Services\Policy;

use App\Models\FilterPolicy;
use App\Models\NetworkAssignment;
use App\Models\User;
use App\Services\Network\NetworkProviderManager;
use App\Services\Network\ProviderRequestFailed;
use App\Services\Network\ReadsContentFilters;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Reads content filters and networks from the controller, keeps the local
 * VLAN mapping in step, and turns a controller filter into a policy snapshot
 * so reconciliation no longer needs copy and paste.
 */
class ControllerSync
{
    private const CACHE_KEY = 'controller.state.v1';

    private const CACHE_SECONDS = 120;

    public function __construct(
        private readonly NetworkProviderManager $providers,
        private readonly PolicyListParser $parser,
    ) {}

    public function reader(): ?ReadsContentFilters
    {
        try {
            $provider = $this->providers->current();
        } catch (Throwable) {
            return null;
        }

        return $provider instanceof ReadsContentFilters ? $provider : null;
    }

    public function available(): bool
    {
        return $this->reader() !== null;
    }

    /**
     * Live controller state, cached briefly because the cloud connector adds
     * a second or two per call.
     *
     * @return array{filters: list<array<string, mixed>>, networks: list<array<string, mixed>>, fetched_at: ?string, error: ?string}
     */
    public function state(bool $refresh = false): array
    {
        $reader = $this->reader();
        if (! $reader) {
            return ['filters' => [], 'networks' => [], 'fetched_at' => null, 'error' => 'The active network provider cannot read content filters. Paste the lists instead.'];
        }

        if ($refresh) {
            Cache::forget(self::CACHE_KEY);
        }

        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $state = ['filters' => $reader->contentFilters(), 'networks' => $reader->networks(), 'fetched_at' => now()->toIso8601String(), 'error' => null];
        } catch (ProviderRequestFailed $exception) {
            return ['filters' => [], 'networks' => [], 'fetched_at' => null, 'error' => $exception->getMessage()];
        } catch (Throwable $exception) {
            report($exception);

            return ['filters' => [], 'networks' => [], 'fetched_at' => null, 'error' => 'The controller could not be reached. Check Settings → Network provider.'];
        }

        Cache::put(self::CACHE_KEY, $state, self::CACHE_SECONDS);
        $this->rememberNetworks($state['networks']);

        return $state;
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, mixed>|null */
    public function filterFor(FilterPolicy $policy, ?array $state = null): ?array
    {
        if (blank($policy->provider_reference)) {
            return null;
        }
        $state ??= $this->state();

        return collect($state['filters'])->first(fn (array $filter) => $filter['id'] === $policy->provider_reference
            || strcasecmp($filter['name'], (string) $policy->provider_reference) === 0);
    }

    /**
     * Replace a policy's snapshot with the controller filter's live lists.
     *
     * @return array{ok: bool, message: string}
     */
    public function pull(FilterPolicy $policy, ?User $actor, bool $refresh = true): array
    {
        $state = $this->state($refresh);
        if ($state['error']) {
            return ['ok' => false, 'message' => $state['error']];
        }

        $filter = $this->filterFor($policy, $state);
        if (! $filter) {
            return ['ok' => false, 'message' => "{$policy->name} is not linked to a controller content filter. Link it under Networks & filters."];
        }

        $allow = $this->parser->parseDetailed(implode("\n", $filter['allow']), 'allow');
        $block = $this->parser->parseDetailed(implode("\n", $filter['block']), 'block');
        $policy->update([
            'provider_reference' => $filter['id'],
            'provider_name' => $filter['name'],
            'current_allowlist' => $allow['entries'],
            'current_blocklist' => $block['entries'],
            'snapshot_at' => now(),
            'snapshot_by_id' => $actor?->id,
        ]);

        return ['ok' => true, 'message' => "Fetched {$filter['name']}: ".count($allow['entries']).' allowed, '.count($block['entries']).' blocked.'];
    }

    /**
     * Link a controller filter to a policy: record the link, map the
     * filter's networks to the policy where they are not mapped yet, fetch
     * its lists and take them as the drift baseline.
     *
     * @param  array<string, mixed>  $filter
     */
    public function link(array $filter, FilterPolicy $policy, ?User $actor, bool $remapNetworks = false): array
    {
        FilterPolicy::query()->whereKeyNot($policy->id)->where('provider_reference', $filter['id'])->update(['provider_reference' => null, 'provider_name' => null]);
        $policy->update(['provider_reference' => $filter['id'], 'provider_name' => $filter['name']]);

        foreach ($filter['network_ids'] as $networkId) {
            NetworkAssignment::query()->where('provider_network_id', $networkId)
                ->when(! $remapNetworks, fn ($query) => $query->whereNull('filter_policy_id'))
                ->update(['filter_policy_id' => $policy->id]);
        }

        $pulled = $this->pull($policy, $actor, refresh: false);
        $checker = app(SyncChecker::class);
        $checker->setBaseline($policy->refresh(), $filter['allow'], $filter['block'], $filter['categories'] ?? []);
        $checker->checkOne($policy, refresh: false);

        return $pulled;
    }

    /**
     * One row per controller network with the policy it is mapped to, the
     * controller filters actually attached, and whether the two agree. A
     * network can sit in more than one controller filter. The policy's own
     * layer is expected there; any other filter is reported as
     * "multiple_filters", because filters combine and a hostname allowed by
     * one is still blocked by another on the same network.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function networkReport(array $state): Collection
    {
        $assignments = NetworkAssignment::query()->with('filterPolicy.layer')->get()->keyBy('provider_network_id');
        $filtersByNetwork = [];
        foreach ($state['filters'] as $filter) {
            foreach ($filter['network_ids'] as $networkId) {
                $filtersByNetwork[$networkId][] = $filter;
            }
        }

        return collect($state['networks'])->map(function (array $network) use ($assignments, $filtersByNetwork): array {
            $assignment = $assignments->get($network['id']);
            $policy = $assignment?->filterPolicy;
            $attached = $filtersByNetwork[$network['id']] ?? [];
            $attachedIds = array_column($attached, 'id');
            // The policy's layer belongs on the same networks; it is expected, not a conflict.
            $layer = $policy?->activeLayer();
            $expected = array_filter([$policy?->provider_reference, $layer?->provider_reference]);
            $others = $policy ? array_values(array_filter($attached, fn (array $filter) => ! in_array($filter['id'], $expected, true))) : [];
            $linked = $policy && in_array($policy->provider_reference, $attachedIds, true);

            $status = match (true) {
                $policy === null && $attached === [] => 'unfiltered',
                $policy === null => 'unmapped',
                blank($policy->provider_reference) => 'policy_unlinked',
                $linked && $others !== [] => 'multiple_filters',
                $linked && $layer && ! in_array($layer->provider_reference, $attachedIds, true) => 'layer_missing',
                $linked => 'ok',
                $attached === [] => 'missing_filter',
                default => 'wrong_filter',
            };

            return $network + [
                'assignment' => $assignment, 'policy' => $policy, 'layer' => $layer, 'attached' => $attached, 'status' => $status, 'others' => $others,
            ];
        });
    }

    /** @param list<array{id: string, name: string, vlan: int|null, purpose: string|null}> $networks */
    private function rememberNetworks(array $networks): void
    {
        foreach ($networks as $network) {
            $assignment = NetworkAssignment::query()->firstOrNew(['provider_network_id' => $network['id']]);
            $assignment->fill(['name' => $network['name'], 'vlan' => $network['vlan'], 'purpose' => $network['purpose'], 'subnet' => $network['subnet'] ?? null]);
            if ($assignment->isDirty()) {
                $assignment->save();
            }
        }
    }
}
