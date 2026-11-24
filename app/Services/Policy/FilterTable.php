<?php

namespace App\Services\Policy;

use App\Models\FilterPolicy;
use App\Models\NetworkType;
use App\Services\Network\FilterSchedule;
use Illuminate\Support\Collection;

/**
 * Rows for a table laid out like UniFi's own Content Filter page (name,
 * networks, categories, SafeSearch, schedule), plus what Filterdesk manages
 * each filter as and, when reports are given, its entries now and what
 * publishing would change. One row per UniFi filter, then any policy not
 * linked to one.
 */
class FilterTable
{
    /**
     * @param  array<string, mixed>  $state  ControllerSync::state()
     * @param  array<string, mixed>  $learned  ControllerLearner::learn()
     * @param  Collection<int, FilterPolicy>  $policies
     * @param  array<int, array<string, mixed>>  $reports  policy id => PolicyEngine::reconcile()
     * @param  array<string, array<string, mixed>>  $suggestions  filter id => importer suggestion (setup preview)
     * @return list<array<string, mixed>>
     */
    public function rows(array $state, array $learned, Collection $policies, array $reports = [], array $suggestions = []): array
    {
        $networks = collect($state['networks'])->keyBy('id');
        $rows = [];
        foreach ($state['filters'] as $filter) {
            $policy = $policies->first(fn (FilterPolicy $policy) => $policy->provider_reference === $filter['id']);
            $lesson = $learned['filters'][$filter['id']] ?? null;
            $suggestion = $suggestions[$filter['id']] ?? null;
            $rows[] = [
                'name' => $filter['name'],
                'paused' => ! ($filter['enabled'] ?? true),
                'policy' => $policy,
                'role' => $this->role($policy, $lesson, $suggestion),
                'networks' => collect($filter['network_ids'])->map(fn ($id) => $networks->has($id) ? $networks[$id]['name'].($networks[$id]['vlan'] ? " ({$networks[$id]['vlan']})" : '') : null)->filter()->values()->all(),
                'categories' => collect($filter['categories'] ?? [])->map(fn ($category) => ucfirst(strtolower(str_replace('_', ' ', (string) $category))))->values()->all(),
                'safe_search' => collect($filter['safe_search'] ?? [])->map(fn ($engine) => ucfirst(strtolower((string) $engine)))->values()->all(),
                'schedule' => $filter['schedule_label'] ?? FilterSchedule::label($filter['schedule'] ?? null),
                'allow' => count($filter['allow']),
                'block' => count($filter['block']),
                'report' => $policy ? ($reports[$policy->id] ?? null) : null,
            ];
        }
        // Linked policies whose filter was deleted in UniFi: listed so they can be deleted, kept or relinked.
        $filterIds = array_column($state['filters'], 'id');
        foreach ($policies->filter(fn (FilterPolicy $policy) => filled($policy->provider_reference) && ! in_array($policy->provider_reference, $filterIds, true)) as $policy) {
            $rows[] = ['name' => $policy->name, 'paused' => false, 'policy' => $policy, 'gone' => true,
                'role' => 'Its UniFi filter '.($policy->provider_name ?: $policy->provider_reference).' was deleted',
                'networks' => [], 'categories' => [], 'safe_search' => [], 'schedule' => '', 'allow' => null, 'block' => null, 'report' => null];
        }
        foreach ($policies->filter(fn (FilterPolicy $policy) => blank($policy->provider_reference)) as $policy) {
            $rows[] = ['name' => $policy->name, 'paused' => false, 'policy' => $policy, 'role' => ($policy->isHoursLayer() ? 'School-hours layer' : ($policy->isLayer() ? 'Blocklist layer' : $policy->typeLabel())).' · not linked to UniFi',
                'networks' => [], 'categories' => [], 'safe_search' => [], 'schedule' => '', 'allow' => null, 'block' => null, 'report' => $reports[$policy->id] ?? null];
        }

        return $rows;
    }

    /** What Filterdesk manages the filter as (or will, in the setup preview). */
    private function role(?FilterPolicy $policy, ?array $lesson, ?array $suggestion): string
    {
        $action = $suggestion['action'] ?? null;
        if ($action === 'sitewide' || ($action === null && $policy?->isSitewide())) {
            return 'Site-wide layer'.(($suggestion['carries_blocks'] ?? $policy?->receives_site_blocks) ? ' · takes the site-wide blocks' : '');
        }
        if ($policy?->isHoursLayer() || $action === 'hours') {
            $parent = $policy?->layerOf?->name ?? $lesson['parent_name'] ?? 'its all-hours filter';

            return 'School-hours layer of '.$parent.' · keeps its allows and blocks';
        }
        if ($policy?->isLayer() || $action === 'layer') {
            return 'Blocklist layer'.($policy?->layerOf ? ' of '.$policy->layerOf->name : '');
        }
        if ($action === 'skip') {
            return 'Not managed';
        }
        $audience = $suggestion['audience'] ?? $policy?->audience ?? $lesson['audience'] ?? null;

        return $audience ? NetworkType::labelFor($audience).' group'.(($lesson['scheduled'] ?? false) ? ' (on a schedule)' : '') : 'Not managed';
    }
}
