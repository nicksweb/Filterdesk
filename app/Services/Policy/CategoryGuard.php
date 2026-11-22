<?php

namespace App\Services\Policy;

use App\Enums\EntrySource;
use App\Enums\ListKind;
use App\Enums\RequestStatus;
use App\Models\FilterPolicy;
use App\Models\RequestEvent;
use App\Models\WebsiteRequest;
use App\Services\AuditLogger;
use App\Services\Domains\DomainCategorizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Keeps smart approvals working when filtering changes. A smart approval
 * gives access without an allow entry when the site's category is not
 * blocked; if a filter later blocks that category (or the site is
 * re-categorised into a blocked one), the approved site would silently be
 * blocked again. This adds the allow entry the approval would have needed.
 *
 * It only looks at hostnames smart-approved without an allow entry, using
 * the categories stored at approval, so it costs no lookups; refresh()
 * re-asks the category service for those hostnames only (cached a week).
 */
class CategoryGuard
{
    public function __construct(
        private readonly PolicyEngine $engine,
        private readonly ControllerSync $sync,
        private readonly DomainCategorizer $categorizer,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Add allow entries where a guarded hostname's category is now blocked.
     *
     * @return list<string> "REF host (category in filter)" for each entry added
     */
    public function check(): array
    {
        $state = $this->sync->state();
        if ($state['error'] ?? false) {
            return [];
        }
        $categoriesByFilter = collect($state['filters'])->mapWithKeys(fn (array $filter) => [$filter['id'] => (array) ($filter['categories'] ?? [])]);
        $added = [];

        foreach ($this->guarded() as $request) {
            $plan = $request->access_plan;
            $policies = $this->engine->policiesFor($request->effectiveScope())->filter(fn (FilterPolicy $policy) => filled($policy->provider_reference));
            foreach ($plan as $domain => $step) {
                if (! in_array($step['action'] ?? 'allow', ['unblock', 'none'], true)) {
                    continue;
                }
                $hit = null;
                foreach ($policies as $policy) {
                    $blocked = array_values(array_intersect((array) ($step['unifi'] ?? []), $categoriesByFilter[$policy->provider_reference] ?? []));
                    if ($blocked !== []) {
                        $hit = [$policy, $blocked[0]];
                        break;
                    }
                }
                if (! $hit) {
                    continue;
                }
                [$policy, $category] = $hit;
                $label = ucfirst(strtolower(str_replace('_', ' ', $category)));

                DB::transaction(function () use ($request, $domain, $policy, $label, &$plan): void {
                    foreach ($this->engine->targetListsFor($request->effectiveScope(), ListKind::Allow) as $list) {
                        $this->engine->upsertEntry($list, $domain, [
                            'source' => EntrySource::Request, 'website_request_id' => $request->id,
                            'note' => "{$request->reference}: {$label} is now blocked in {$policy->name}; kept allowed",
                        ]);
                    }
                    $plan[$domain] = ['action' => 'allow', 'reason' => "Allow entry added later: {$label} became a blocked category in {$policy->name}."] + $plan[$domain];
                    $request->forceFill(['access_plan' => $plan])->save();
                    RequestEvent::query()->create([
                        'website_request_id' => $request->id, 'type' => 'category_guard', 'internal' => true,
                        'message' => "{$domain} was approved without an allow entry because its category was not blocked. {$label} is now blocked in {$policy->name}, so an allow entry was added to keep the approved access.",
                    ]);
                });
                $this->audit->log('policy.category_guard_allowed', $request, ['domain' => $domain, 'category' => $category, 'policy' => $policy->name]);
                $added[] = "{$request->reference} {$domain} ({$label} in {$policy->name})";
            }
        }

        return $added;
    }

    /**
     * Re-ask the category service about guarded hostnames (cached answers are
     * reused; at most one fresh lookup per hostname a week), store any new
     * UniFi categories on the plan, then check.
     *
     * @return list<string>
     */
    public function refresh(int $limit = 200): array
    {
        if (! $this->categorizer->enabled()) {
            return $this->check();
        }
        $looked = 0;
        foreach ($this->guarded() as $request) {
            $plan = $request->access_plan;
            $changed = false;
            foreach ($plan as $domain => $step) {
                if (! in_array($step['action'] ?? 'allow', ['unblock', 'none'], true) || $looked >= $limit) {
                    continue;
                }
                $looked++;
                $lookup = $this->categorizer->lookup($domain);
                if ($lookup['ok']) {
                    $ids = array_values(array_column($lookup['unifi'], 'id'));
                    if ($ids !== (array) ($step['unifi'] ?? [])) {
                        $plan[$domain]['unifi'] = $ids;
                        $changed = true;
                    }
                }
            }
            if ($changed) {
                $request->forceFill(['access_plan' => $plan])->save();
            }
        }

        return $this->check();
    }

    /** @return Collection<int, WebsiteRequest> */
    private function guarded()
    {
        return WebsiteRequest::query()->where('request_type', '!=', 'block')
            ->whereIn('status', [RequestStatus::Approved, RequestStatus::ActionRequired, RequestStatus::Active, RequestStatus::Failed])
            ->whereNotNull('access_plan')->get()
            ->filter(fn (WebsiteRequest $request) => collect($request->access_plan)->contains(fn ($step) => in_array($step['action'] ?? null, ['unblock', 'none'], true)));
    }
}
