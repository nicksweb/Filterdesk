<?php

namespace App\Http\Controllers\Api;

use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Models\FilterPolicy;
use App\Models\PolicyEntry;
use App\Models\PolicyRelease;
use App\Models\WebsiteRequest;
use App\Services\Coverage;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Prometheus text metrics for monitoring (an API token with read access). */
class MetricsController extends Controller
{
    public function __invoke(Coverage $coverage): Response
    {
        $lines = [];
        $metric = function (string $name, string $help, float|int|null $value, array $labels = []) use (&$lines): void {
            if ($value === null) {
                return;
            }
            $lines[] = "# HELP itrequests_{$name} {$help}";
            $lines[] = "# TYPE itrequests_{$name} gauge";
            $label = $labels ? '{'.collect($labels)->map(fn ($v, $k) => $k.'="'.addslashes((string) $v).'"')->join(',').'}' : '';
            $lines[] = "itrequests_{$name}{$label} {$value}";
        };

        $metric('requests_open', 'Requests awaiting a decision', WebsiteRequest::query()->whereIn('status', RequestStatus::open())->count());
        $metric('requests_awaiting_second_approval', 'Requests waiting for a second IT officer', WebsiteRequest::query()->where('status', RequestStatus::AwaitingSecondApproval)->count());
        $metric('proposals_pending', 'Site-wide list changes waiting for a second IT officer', PolicyEntry::query()->pending()->count());
        $metric('reviews_overdue', 'Exceptions past their review date', PolicyEntry::query()->reviewable()->where('review_at', '<', now())->count());
        $metric('filters_out_of_sync', 'Linked content filters not in sync', FilterPolicy::query()->where('enabled', true)->whereNotNull('provider_reference')->where('sync_status', '!=', 'in_sync')->count());
        $metric('coverage_percent', 'Coverage score', $coverage->report()['score']);
        $last = PolicyRelease::query()->whereNotNull('number')->latest('id')->first();
        $metric('policy_version', 'Latest published policy version', $last?->number);
        $metric('publishes_failed_7d', 'Failed or partial publishes in the last 7 days', PolicyRelease::query()->whereIn('status', ['failed', 'partial'])->where('created_at', '>=', now()->subWeek())->count());
        $heartbeat = Cache::get('heartbeat.scheduler');
        $metric('scheduler_heartbeat_age_seconds', 'Seconds since the scheduler last ran', $heartbeat ? now()->diffInSeconds($heartbeat, true) : null);
        $metric('queue_jobs', 'Jobs waiting in the queue', rescue(fn () => DB::table('jobs')->count(), null, false));
        $metric('queue_failed_jobs', 'Failed queue jobs', rescue(fn () => DB::table('failed_jobs')->count(), null, false));

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; version=0.0.4']);
    }
}
