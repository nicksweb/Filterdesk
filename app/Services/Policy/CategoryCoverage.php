<?php

namespace App\Services\Policy;

use App\Models\FilterPolicy;
use App\Services\Domains\DomainCategorizer;

/**
 * Which linked UniFi filters already block a site by one of its content
 * categories, school-hours layers included ("chatgpt.com is already blocked
 * in school hours by Artificial intelligence"). Needs website categories
 * (Settings → Website categories); without them it knows nothing.
 */
class CategoryCoverage
{
    public function __construct(private readonly DomainCategorizer $categorizer, private readonly ControllerSync $sync) {}

    /**
     * @param  list<string>  $domains
     * @return array<string, list<array{policy: FilterPolicy, filter: string, category: string, school_hours: bool}>>
     */
    public function forDomains(array $domains): array
    {
        if ($domains === [] || ! $this->categorizer->enabled()) {
            return [];
        }
        $lookups = rescue(fn () => $this->categorizer->many($domains), [], false);
        $state = $this->sync->state();
        $filters = collect($state['filters'] ?? [])->keyBy('id');
        $policies = FilterPolicy::query()->whereNotNull('provider_reference')->where('enabled', true)->get();

        $result = [];
        foreach ($lookups as $domain => $lookup) {
            foreach ((array) ($lookup['unifi'] ?? []) as $category) {
                foreach ($policies as $policy) {
                    $filter = $filters->get($policy->provider_reference);
                    if ($filter && ($filter['enabled'] ?? true) && in_array($category['id'], (array) ($filter['categories'] ?? []), true)) {
                        $result[$domain][] = ['policy' => $policy, 'filter' => (string) $filter['name'], 'category' => (string) $category['label'], 'school_hours' => $policy->isHoursLayer()];
                    }
                }
            }
        }

        return $result;
    }
}
