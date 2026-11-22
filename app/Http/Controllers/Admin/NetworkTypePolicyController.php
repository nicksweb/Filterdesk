<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EntrySource;
use App\Enums\ListKind;
use App\Enums\ListScope;
use App\Http\Controllers\Controller;
use App\Models\FilterPolicy;
use App\Models\NetworkType;
use App\Models\PolicyEntry;
use App\Models\PolicyList;
use App\Services\Policy\ControllerSync;
use Illuminate\View\View;

/**
 * One page per network type: what that kind of network may reach. Its
 * filters and their blocked categories, exam mode, schedules running, and
 * the exceptions (by source) that apply to it, with each filter's health.
 */
class NetworkTypePolicyController extends Controller
{
    public function show(NetworkType $type, ControllerSync $sync): View
    {
        $policies = FilterPolicy::query()->where('audience', $type->key)->ordered()->get();
        $state = rescue(fn () => $sync->state(), ['filters' => []], false);
        $lists = PolicyList::query()->get()->filter(fn (PolicyList $list) => $list->scope === ListScope::Site
            || ($list->scope === ListScope::Audience && $list->audience === $type->key)
            || ($list->scope === ListScope::Policy && $policies->contains('id', $list->filter_policy_id)))->values();

        $counts = PolicyEntry::query()->whereIn('policy_list_id', $lists->pluck('id'))
            ->selectRaw('policy_list_id, source, count(*) as total, sum(case when schedule is not null then 1 else 0 end) as scheduled, sum(case when expires_at is not null then 1 else 0 end) as timed')
            ->groupBy('policy_list_id', 'source')->get();

        return view('admin.policy.type', [
            'type' => $type,
            'policies' => $policies->map(fn (FilterPolicy $policy) => ['policy' => $policy, 'filter' => $sync->filterFor($policy, $state)]),
            'lists' => $lists,
            'summary' => collect([ListKind::Allow, ListKind::Block])->mapWithKeys(fn (ListKind $kind) => [$kind->value => collect(EntrySource::cases())->mapWithKeys(fn (EntrySource $source) => [
                $source->value => (int) $counts->whereIn('policy_list_id', $lists->where('kind', $kind)->pluck('id'))->where('source', $source->value)->sum('total'),
            ])->filter()]),
            'scheduled' => (int) $counts->sum('scheduled'),
            'timed' => (int) $counts->sum('timed'),
            'examList' => $lists->firstWhere('purpose', 'exam'),
        ]);
    }
}
