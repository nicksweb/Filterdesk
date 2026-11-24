<?php

namespace App\Services\Policy;

use App\Enums\ListKind;
use App\Enums\ListScope;
use App\Models\FilterPolicy;
use App\Models\NetworkType;
use App\Models\PolicyList;
use Illuminate\Support\Collection;

/**
 * The whole chain for one network in one place: the UniFi network, every
 * content filter UniFi applies to it (and why each is there), the
 * Filterdesk policy behind each filter, and the lists that fill it, with
 * the categories each filter blocks. Read only.
 */
class FilteringMap
{
    public function __construct(
        private readonly ControllerSync $sync,
        private readonly PolicyEngine $engine,
    ) {}

    /**
     * Every network with its type and filters, for the overview.
     *
     * @return array{error: ?string, networks: Collection<int, array<string, mixed>>}
     */
    public function overview(bool $refresh = false): array
    {
        $state = $this->sync->state($refresh);
        if ($state['error']) {
            return ['error' => $state['error'], 'networks' => collect()];
        }

        return ['error' => null, 'networks' => $this->sync->networkReport($state)->map(fn (array $network) => $network + [
            'type' => $network['policy'] ? NetworkType::labelFor($network['policy']->audience) : null,
            'summary' => $this->statusText($network),
        ])->sortBy([fn (array $network) => $network['attached'] === [] ? 1 : 0, fn (array $network) => $network['vlan'] ?? 0])->values()];
    }

    /**
     * The chain for one network.
     *
     * @param  array<string, mixed>  $network  a row from overview()
     * @return array{filters: list<array<string, mixed>>, missing: list<string>}
     */
    public function trace(array $network): array
    {
        $linked = FilterPolicy::query()->whereNotNull('provider_reference')->with('layerOf')->get()->keyBy('provider_reference');
        /** @var FilterPolicy|null $own */
        $own = $network['policy'];

        $filters = collect($network['attached'])->map(function (array $filter) use ($linked, $own): array {
            /** @var FilterPolicy|null $policy */
            $policy = $linked->get($filter['id']);

            return [
                'filter' => $filter,
                'policy' => $policy,
                'role' => $this->role($policy, $own),
                'expected' => $policy !== null && $own !== null && $this->expected($policy, $own),
                'lists' => $policy ? $this->lists($policy) : collect(),
                'desired' => $policy && $policy->enabled ? $this->desiredCounts($policy) : null,
            ];
        })->sortBy(fn (array $row) => match (true) {
            $row['policy']?->is($own) ?? false => 0,
            $row['expected'] => 1,
            default => 2,
        })->values()->all();

        $attached = array_column($network['attached'], 'id');
        $missing = [];
        if ($own && filled($own->provider_reference) && ! in_array($own->provider_reference, $attached, true)) {
            $missing[] = ($own->provider_name ?: $own->name).' (this network\'s '.NetworkType::labelFor($own->audience).' filter)';
        }
        $layer = $own?->activeLayer();
        if ($layer && in_array($own->provider_reference, $attached, true) && ! in_array($layer->provider_reference, $attached, true)) {
            $missing[] = ($layer->provider_name ?: $layer->name).' (the layer that carries its blocklist feeds)';
        }

        return ['filters' => $filters, 'missing' => $missing];
    }

    /** Why a filter is on this network, in words. */
    private function role(?FilterPolicy $policy, ?FilterPolicy $own): string
    {
        return match (true) {
            $policy === null => 'Only in UniFi: Filterdesk does not manage this filter',
            $policy->isSitewide() => 'Site-wide filter: on every network',
            $own !== null && $policy->is($own) => 'This network\'s '.NetworkType::labelFor($own->audience).' filter',
            $policy->isHoursLayer() && $policy->layer_of_id === $own?->id => 'School-hours filter for '.($own->provider_name ?: $own->name).': on only while its schedule runs',
            $policy->isLayer() && $policy->layer_of_id === $own?->id => 'Layer of '.($own->provider_name ?: $own->name).': carries its blocklist feeds',
            $own !== null && $policy->audience === $own->audience => 'Another '.NetworkType::labelFor($own->audience).' filter',
            default => 'A '.NetworkType::labelFor(($policy->layerOf ?? $policy)->audience).' filter: not expected on a network of this type',
        };
    }

    private function expected(FilterPolicy $policy, FilterPolicy $own): bool
    {
        return $policy->isSitewide() || $policy->is($own) || $policy->layer_of_id === $own->id || $policy->audience === $own->audience;
    }

    /**
     * The lists that fill a policy's filter, most general first.
     *
     * @return Collection<int, PolicyList>
     */
    private function lists(FilterPolicy $policy): Collection
    {
        return PolicyList::query()->appliesTo($policy)->with('feed')
            ->withCount(['entries' => fn ($query) => $query->effective()])
            ->get()
            ->reject(fn (PolicyList $list) => $list->purpose === 'exam' && ! NetworkType::examActiveFor($list->audience))
            ->sortBy([fn (PolicyList $list) => match ($list->scope) {
                ListScope::Site => 0,
                ListScope::Audience => 1,
                ListScope::Policy => 2,
            }, fn (PolicyList $list) => $list->kind === ListKind::Allow ? 0 : 1, fn (PolicyList $list) => $list->name])
            ->values();
    }

    /** @return array{allow: int, block: int, protected: int} */
    private function desiredCounts(FilterPolicy $policy): array
    {
        $desired = rescue(fn () => $this->engine->desired($policy), null, false);

        return $desired ? ['allow' => count($desired['allow']), 'block' => count($desired['block']), 'protected' => count($desired['protected'] ?? [])] : ['allow' => 0, 'block' => 0, 'protected' => 0];
    }

    /** @param array<string, mixed> $network */
    private function statusText(array $network): string
    {
        return match ($network['status']) {
            'ok' => 'As expected',
            'multiple_filters' => 'Has other filters too',
            'layer_missing' => 'Its layer is missing',
            'hours_only' => 'Filtered in school hours only',
            'missing_filter' => 'No filter in UniFi',
            'wrong_filter' => 'UniFi uses a different filter',
            'policy_unlinked' => 'Its policy is not linked to UniFi',
            'unmapped' => 'Filtered in UniFi, no type here',
            'unfiltered' => 'Not filtered',
            default => (string) $network['status'],
        };
    }
}
