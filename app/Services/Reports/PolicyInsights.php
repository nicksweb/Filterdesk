<?php

namespace App\Services\Reports;

use App\Enums\RequestStatus;
use App\Models\AuditEvent;
use App\Models\CalendarPeriod;
use App\Models\NetworkAssignment;
use App\Models\NetworkType;
use App\Models\PolicyEntry;
use App\Models\PolicyRelease;
use App\Models\WebsiteRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The termly report and the policy suggestions drawn from requests, checks
 * and policy state. No browsing data is used: only what people asked for
 * and what IT decided.
 */
class PolicyInsights
{
    /**
     * The current term from the school calendar, or the last 90 days.
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable, label: string}
     */
    public function defaultPeriod(): array
    {
        $term = CalendarPeriod::query()->where('kind', 'term')->where('start_date', '<=', now()->toDateString())->orderByDesc('start_date')->first();
        if ($term) {
            $end = CarbonImmutable::parse($term->end_date);

            return ['from' => CarbonImmutable::parse($term->start_date)->startOfDay(), 'to' => ($end->isPast() ? $end : CarbonImmutable::now())->endOfDay(), 'label' => $term->name];
        }

        return ['from' => CarbonImmutable::now()->subDays(90)->startOfDay(), 'to' => CarbonImmutable::now()->endOfDay(), 'label' => 'Last 90 days'];
    }

    /** @return array<string, mixed> */
    public function report(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $requests = WebsiteRequest::query()->whereBetween('created_at', [$from, $to])->get();
        $decided = $requests->filter(fn (WebsiteRequest $request) => $request->approved_at || $request->status === RequestStatus::Rejected);
        $hours = $decided->map(fn (WebsiteRequest $request) => $request->created_at->diffInMinutes($request->approved_at ?? $request->resolved_at ?? $request->updated_at) / 60)->sort()->values();
        $types = NetworkType::query()->ordered()->get();
        $approved = $requests->filter(fn (WebsiteRequest $request) => $request->approved_at !== null);

        return [
            'from' => $from, 'to' => $to,
            'submitted' => $requests->count(),
            'approved' => $approved->count(),
            'declined' => $requests->where('status', RequestStatus::Rejected)->count(),
            'open' => $requests->filter(fn (WebsiteRequest $request) => $request->status->isOpen())->count(),
            'median_hours' => $hours->isEmpty() ? null : round((float) $hours->get(intdiv($hours->count(), 2)), 1),
            'by_category' => $requests->groupBy('category')->map->count()->sortDesc()->all(),
            'by_department' => $requests->filter(fn (WebsiteRequest $request) => filled($request->department))->groupBy('department')->map->count()->sortDesc()->all(),
            'by_type' => $types->mapWithKeys(fn (NetworkType $type) => [$type->name => $approved->filter(fn (WebsiteRequest $request) => $request->effectiveScope()->siteWide || $request->effectiveScope()->includes($type->key))->count()])->filter()->all(),
            'decline_reasons' => $requests->where('status', RequestStatus::Rejected)->map(fn (WebsiteRequest $request) => str((string) $request->resolution)->limit(80)->toString())->countBy()->sortDesc()->take(8)->all(),
            'versions' => PolicyRelease::query()->whereNotNull('number')->whereBetween('created_at', [$from, $to])->count(),
            'rollbacks' => PolicyRelease::query()->whereNotNull('rollback_of_id')->whereBetween('created_at', [$from, $to])->count(),
            'overrides' => AuditEvent::query()->whereIn('action', ['website_request.two_person_override', 'policy.two_person_override'])->whereBetween('created_at', [$from, $to])->count(),
            'kept' => AuditEvent::query()->where('action', 'policy.exception_kept')->whereBetween('created_at', [$from, $to])->count(),
            'expired_unreviewed' => AuditEvent::query()->where('action', 'policy.exception_expired_unreviewed')->whereBetween('created_at', [$from, $to])->count(),
            'exceptions' => PolicyEntry::query()->reviewable()->count(),
            'unfiltered_networks' => NetworkAssignment::query()->whereNull('filter_policy_id')->orderBy('vlan')->get(['name', 'vlan']),
            'insights' => $this->insights($from, $to),
        ];
    }

    /**
     * Patterns worth a policy change.
     *
     * @return list<array{title: string, detail: string, link: ?string, action: ?string}>
     */
    public function insights(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $insights = [];
        $requests = WebsiteRequest::query()->where('created_at', '>=', $from->subYear())->whereNotNull('domain_categories')->get();
        $termRequests = $requests->filter(fn (WebsiteRequest $request) => $request->created_at->between($from, $to));

        // Demand for one category on one network type.
        foreach (NetworkType::query()->ordered()->get() as $type) {
            $forType = $termRequests->filter(fn (WebsiteRequest $request) => ! $request->isBlock() && ($request->access_scope->siteWide || $request->access_scope->includes($type->key)));
            $byCategory = $this->byUnifiCategory($forType);
            foreach ($byCategory as $category => $items) {
                $approved = $items->filter(fn (WebsiteRequest $request) => $request->approved_at !== null)->count();
                $declined = $items->where('status', RequestStatus::Rejected)->count();
                $label = ucfirst(strtolower(str_replace('_', ' ', $category)));
                if ($approved >= 5) {
                    $insights[] = ['title' => "{$approved} {$label} requests approved for {$type->name} this period",
                        'detail' => 'Consider allowing the category, or a scheduled allow, instead of one site at a time. Check the effect first.',
                        'link' => route('admin.policy.explorer', ['category' => $category, 'change' => 'unblock', 'whatif_type' => $type->key]), 'action' => 'See the effect'];
                }
                if ($declined >= 5 && $declined / max(1, $items->count()) >= 0.9) {
                    $insights[] = ['title' => "{$label} is declined ".round(100 * $declined / $items->count())."% of the time for {$type->name}",
                        'detail' => 'Consider saying so on the request form, so people do not wait for a no.',
                        'link' => route('admin.settings.show', 'approval'), 'action' => 'Edit the request form'];
                }
            }
        }

        // One department asking for the same category again and again.
        foreach ($termRequests->filter(fn (WebsiteRequest $request) => filled($request->department))->groupBy('department') as $department => $items) {
            foreach ($this->byUnifiCategory($items) as $category => $group) {
                if ($group->count() >= 5) {
                    $label = ucfirst(strtolower(str_replace('_', ' ', $category)));
                    $insights[] = ['title' => "{$group->count()} {$label} requests from {$department} this period",
                        'detail' => 'Consider a scheduled allow for that department\'s classes (a recurring request that follows the school calendar).',
                        'link' => route('admin.policy.explorer', ['category' => $category, 'change' => 'unblock']), 'action' => 'See the effect'];
                }
            }
        }

        // The same site approved for a limited time again and again.
        $renewed = $requests->filter(fn (WebsiteRequest $request) => $request->approved_at && $request->expires_at)
            ->flatMap(fn (WebsiteRequest $request) => collect($request->domains)->map(fn (string $domain) => [$domain, $request->id]))
            ->groupBy(0)->map(fn (Collection $rows) => $rows->pluck(1)->unique()->count())->filter(fn (int $count) => $count >= 3)->sortDesc();
        foreach ($renewed->take(5) as $domain => $count) {
            $insights[] = ['title' => "{$domain} was approved for a limited time {$count} times this year",
                'detail' => 'Consider a permanent or recurring allow so it does not need asking for again.',
                'link' => route('admin.policy.explorer', ['site' => $domain]), 'action' => 'Explore it'];
        }

        $stale = PolicyEntry::query()->reviewable()->where(fn ($query) => $query->where('last_seen_at', '<', now()->subYear())->orWhere(fn ($query) => $query->whereNull('last_seen_at')->where('created_at', '<', now()->subYear())))->count();
        if ($stale > 0) {
            $insights[] = ['title' => "{$stale} exception(s) not seen in a check or request for a year",
                'detail' => 'They may no longer be needed; they are listed under Reviews.', 'link' => route('reviews.index'), 'action' => 'Open Reviews'];
        }

        return $insights;
    }

    /**
     * Rows for the CSV export: one per request in the period.
     *
     * @return list<list<string>>
     */
    public function csv(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = [['Reference', 'Submitted', 'Type', 'Websites', 'Networks', 'Category', 'Department', 'Status', 'Decided', 'Approved by', 'Window']];
        WebsiteRequest::query()->whereBetween('created_at', [$from, $to])->with('approvedBy')->orderBy('created_at')->each(function (WebsiteRequest $request) use (&$rows): void {
            $rows[] = [
                $request->reference, $request->created_at->local()->format('Y-m-d H:i'), $request->isBlock() ? 'Block' : 'Access', implode(' ', $request->domains),
                $request->effectiveScope()->label(), (string) $request->category, (string) $request->department, $request->status->label(),
                ($request->approved_at ?? $request->resolved_at)?->local()->format('Y-m-d H:i') ?? '', $request->approvedBy?->name ?? '', $request->approved_at ? $request->windowLabel() : '',
            ];
        });

        return $rows;
    }

    /** @return Collection<string, Collection<int, WebsiteRequest>> */
    private function byUnifiCategory(Collection $requests): Collection
    {
        $groups = [];
        foreach ($requests as $request) {
            $categories = collect((array) $request->domain_categories)->flatMap(fn ($lookup) => array_column((array) ($lookup['unifi'] ?? []), 'id'))->unique();
            foreach ($categories as $category) {
                $groups[$category][] = $request;
            }
        }

        return collect($groups)->map(fn (array $items) => collect($items));
    }
}
