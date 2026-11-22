<?php

namespace App\Services\Policy;

use App\Enums\ListKind;
use App\Models\FilterPolicy;
use App\Models\NetworkType;
use App\Models\PolicyEntry;
use App\Models\WebsiteRequest;
use Illuminate\Support\Collection;

/**
 * The consequences of a policy change before anyone makes it, worked out
 * from the categories stored on requests (no extra lookups): which approved
 * sites a category block would cut off, which keep working because an
 * allow entry overrides it, and which open requests are in the category.
 */
class WhatIf
{
    public function __construct(private readonly PolicyEngine $engine) {}

    /**
     * @return array{type: NetworkType, category: string, block: bool, policies: list<string>, cut_off: list<array{domain: string, request: WebsiteRequest}>, kept: list<array{domain: string, request: WebsiteRequest, list: string}>, freed: list<array{domain: string, list: string}>, open: list<WebsiteRequest>, summary: string}
     */
    public function category(NetworkType $type, string $category, bool $block): array
    {
        $category = strtoupper($category);
        $label = ucfirst(strtolower(str_replace('_', ' ', $category)));
        $policies = FilterPolicy::query()->where('enabled', true)->whereNull('layer_of_id')->where('audience', $type->key)->get();
        $inCategory = fn (WebsiteRequest $request, string $domain) => collect((array) ($request->domain_categories[$domain]['unifi'] ?? []))->contains('id', $category);

        $requests = WebsiteRequest::query()->whereNotNull('domain_categories')->get()
            ->filter(fn (WebsiteRequest $request) => ! $request->isBlock() && ($request->effectiveScope()->siteWide || $request->effectiveScope()->includes($type->key)));

        $cutOff = [];
        $kept = [];
        $freed = [];
        foreach ($requests->filter(fn (WebsiteRequest $request) => $request->status->isGranted()) as $request) {
            foreach ($request->listedDomains() as $domain) {
                $base = collect(array_keys((array) $request->domain_categories))->first(fn (string $candidate) => $domain === $candidate || str_ends_with($domain, ".{$candidate}"));
                if (! $base || ! $inCategory($request, $base)) {
                    continue;
                }
                $allow = $this->allowEntry($domain, $policies);
                if ($block) {
                    $allow ? $kept[] = ['domain' => $domain, 'request' => $request, 'list' => $allow->policyList->name] : $cutOff[] = ['domain' => $domain, 'request' => $request];
                } elseif ($allow && $allow->source->value === 'request') {
                    $freed[] = ['domain' => $domain, 'list' => $allow->policyList->name];
                }
            }
        }
        $open = $requests->filter(fn (WebsiteRequest $request) => $request->status->isOpen() && collect($request->domains)->contains(fn (string $domain) => $inCategory($request, $domain)))->values()->all();

        $summary = $block
            ? "Blocking {$label} for {$type->name} would cut off ".count($cutOff).' approved site(s), keep '.count($kept).' working through their allow entries, and affect '.count($open).' open request(s).'
            : "Unblocking {$label} for {$type->name} would make ".count($freed).' allow entr(ies) unnecessary; '.count($open).' open request(s) are in the category.';

        return ['type' => $type, 'category' => $label, 'block' => $block, 'policies' => $policies->pluck('name')->all(),
            'cut_off' => $cutOff, 'kept' => $kept, 'freed' => $freed, 'open' => $open, 'summary' => $summary];
    }

    /** @param Collection<int, FilterPolicy> $policies */
    private function allowEntry(string $domain, $policies): ?PolicyEntry
    {
        return $this->engine->locate($domain)->first(fn (PolicyEntry $entry) => $entry->policyList->kind === ListKind::Allow && $entry->isEffective()
            && $policies->contains(fn (FilterPolicy $policy) => $entry->policyList->appliesTo($policy)));
    }
}
