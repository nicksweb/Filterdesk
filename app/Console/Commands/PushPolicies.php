<?php

namespace App\Console\Commands;

use App\Models\FilterPolicy;
use App\Models\PolicyEntry;
use App\Services\Policy\ControllerPusher;
use App\Support\Schedule;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Pushes every linked policy whose controller filter is out of step, so
 * expiries, scheduled starts and list edits reach the controller without
 * anyone clicking. Does nothing unless automatic pushing is turned on.
 */
class PushPolicies extends Command
{
    private const BOUNDARY_KEY = 'policy.window_boundary_checked_at';

    protected $signature = 'policy:push
        {--force : Push even when automatic pushing is off (writes must still be enabled)}
        {--boundaries : Only push policies where a timed entry opened or closed since the last run}';

    protected $description = 'Apply pending policy changes to the linked controller content filters';

    public function handle(ControllerPusher $pusher): int
    {
        if (! $pusher->enabled() || (! $pusher->automatic() && ! $this->option('force'))) {
            $this->line('Automatic pushing is off.');

            return self::SUCCESS;
        }

        if ($this->option('boundaries')) {
            $policies = $this->policiesAtBoundary();
            if ($policies->isEmpty()) {
                return self::SUCCESS;
            }
            $results = $policies->map(fn (FilterPolicy $policy) => ['policy' => $policy] + $pusher->push($policy, null, 'window'));
        } else {
            $results = $pusher->pushAll(null, 'schedule');
        }
        foreach ($results as $result) {
            $this->line(($result['ok'] ? 'OK   ' : 'FAIL ').$result['message']);
        }

        return $results->every(fn (array $result) => $result['ok']) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Linked policies affected by a timed entry whose effective start
     * (start minus lead) or end (end plus lag) passed since the last run.
     *
     * @return Collection<int, FilterPolicy>
     */
    private function policiesAtBoundary(): Collection
    {
        $now = now();
        $last = Carbon::parse(Cache::get(self::BOUNDARY_KEY, $now->copy()->subMinutes(2)->toIso8601String()));
        Cache::forever(self::BOUNDARY_KEY, $now->toIso8601String());
        ['lead' => $lead, 'lag' => $lag] = PolicyEntry::margins();

        $lists = PolicyEntry::query()->with('policyList')
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->where('starts_at', '>', $last->copy()->addMinutes($lead))->where('starts_at', '<=', $now->copy()->addMinutes($lead)))
                ->orWhere(fn ($query) => $query->where('expires_at', '>', $last->copy()->subMinutes($lag))->where('expires_at', '<=', $now->copy()->subMinutes($lag))))
            ->get()->pluck('policyList')->unique('id');

        // Recurring entries whose schedule switched on or off since the last run.
        $scheduled = PolicyEntry::query()->with('policyList')->whereNotNull('schedule')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $last->copy()->subDay()))->get()
            ->filter(function (PolicyEntry $entry) use ($last, $now): bool {
                $schedule = Schedule::fromArray($entry->schedule);

                return $schedule !== null && $schedule->isActive($last) !== $schedule->isActive($now);
            });
        $lists = $lists->merge($scheduled->pluck('policyList'))->unique('id');

        if ($lists->isEmpty()) {
            return collect();
        }

        return FilterPolicy::query()->where('enabled', true)->whereNotNull('provider_reference')->ordered()->get()
            ->filter(fn (FilterPolicy $policy) => $lists->contains(fn ($list) => $list->appliesTo($policy)))
            ->sortBy(fn (FilterPolicy $policy) => $policy->isLayer() ? 0 : 1)->values();
    }
}
