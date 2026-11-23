<?php

namespace App\Services;

use App\Models\FilterPolicy;
use App\Models\FirewallList;
use App\Models\NetworkAssignment;
use App\Models\NetworkProfile;
use App\Models\PolicyEntry;
use App\Models\PolicyRelease;
use App\Services\Policy\FirewallListPusher;
use App\Services\Website\ProbeDiagnostics;

/**
 * One score for how completely the policy is in force, from parts that
 * each link to their fix: networks with a filter, filters in sync, zones
 * covered by firewall IP lists, probes working, publishes not failing and
 * reviews not overdue. Reads only stored state and cached controller data.
 */
class Coverage
{
    /** @return array{score: ?int, parts: list<array{key: string, label: string, ok: ?bool, value: string, detail: string, link: string}>} */
    public function report(): array
    {
        $parts = [];

        $networks = NetworkAssignment::query()->get(['name', 'vlan', 'filter_policy_id']);
        $unfiltered = $networks->whereNull('filter_policy_id');
        $parts[] = ['key' => 'networks', 'label' => 'Networks with a filter', 'ok' => $networks->isEmpty() ? null : $unfiltered->isEmpty(),
            'value' => $networks->isEmpty() ? '—' : ($networks->count() - $unfiltered->count()).' of '.$networks->count(),
            'detail' => $unfiltered->isEmpty() ? 'Every mapped network has a filter policy.' : 'No filter: '.$unfiltered->map(fn ($network) => $network->name.($network->vlan ? " (VLAN {$network->vlan})" : ''))->join(', ').'.',
            'link' => route('admin.policy.networks.index')];

        $linked = FilterPolicy::query()->where('enabled', true)->whereNotNull('provider_reference')->get(['name', 'sync_status']);
        $outOfStep = $linked->whereIn('sync_status', ['drift', 'error', 'changes_due']);
        $parts[] = ['key' => 'sync', 'label' => 'Filters in sync', 'ok' => $linked->isEmpty() ? null : $outOfStep->isEmpty(),
            'value' => $linked->isEmpty() ? '—' : ($linked->count() - $outOfStep->count()).' of '.$linked->count(),
            'detail' => $outOfStep->isEmpty() ? 'Every linked filter matches its policy.' : 'Out of step: '.$outOfStep->pluck('name')->join(', ').'.',
            'link' => route('admin.policy.changes.index')];

        $parts[] = $this->zones();

        $profiles = NetworkProfile::query()->where('enabled', true)->get();
        $failing = $profiles->filter(fn (NetworkProfile $profile) => in_array(app(ProbeDiagnostics::class)->cached($profile)['state'] ?? 'unknown', ['fail', 'todo'], true));
        $parts[] = ['key' => 'probes', 'label' => 'Probes working', 'ok' => $profiles->isEmpty() ? null : $failing->isEmpty(),
            'value' => $profiles->isEmpty() ? '—' : ($profiles->count() - $failing->count()).' of '.$profiles->count(),
            'detail' => $failing->isEmpty() ? 'Every probe passed its last checklist.' : 'Needs work: '.$failing->pluck('name')->join(', ').'.',
            'link' => route('admin.settings.show', 'profiles')];

        $failed = PolicyRelease::query()->whereIn('status', ['failed', 'partial'])->where('created_at', '>=', now()->subWeek())->count();
        $parts[] = ['key' => 'publishes', 'label' => 'Publishes this week', 'ok' => $failed === 0, 'value' => $failed === 0 ? 'No failures' : "{$failed} failed",
            'detail' => $failed === 0 ? 'Every controller write in the last 7 days succeeded.' : 'Some versions did not fully reach the controller; it retries automatic pushes.', 'link' => route('admin.policy.changes.index')];

        $overdue = PolicyEntry::query()->reviewable()->whereNull('pending_action')->where('review_at', '<', now())->count();
        $parts[] = ['key' => 'reviews', 'label' => 'Reviews up to date', 'ok' => $overdue === 0, 'value' => $overdue === 0 ? 'None due' : "{$overdue} due",
            'detail' => $overdue === 0 ? 'No exception is past its review date.' : 'Exceptions past their review date are removed after the grace period unless kept.', 'link' => route('reviews.index')];

        $known = collect($parts)->whereNotNull('ok');

        return ['score' => $known->isEmpty() ? null : (int) round(100 * $known->where('ok', true)->count() / $known->count()), 'parts' => $parts];
    }

    /** @return array{key: string, label: string, ok: ?bool, value: string, detail: string, link: string} */
    private function zones(): array
    {
        $part = ['key' => 'zones', 'label' => 'Zones covered by IP lists', 'link' => route('admin.policy.blocklists.index')];
        $lists = FirewallList::query()->where('enabled', true)->whereNotNull('rule_zones')->get();
        if ($lists->isEmpty()) {
            return $part + ['ok' => null, 'value' => '—', 'detail' => 'No firewall IP list has blocking rules yet.'];
        }
        $zones = rescue(fn () => app(FirewallListPusher::class)->manager()?->firewallZones() ?? [], [], false);
        $client = collect($zones)->reject(fn (array $zone) => in_array(strtolower($zone['name']), ['external', 'gateway'], true) || ($zone['networks'] ?? []) === []);
        if ($client->isEmpty()) {
            return $part + ['ok' => null, 'value' => '—', 'detail' => 'The controller zones could not be read.'];
        }
        $covered = $lists->flatMap(fn (FirewallList $list) => $list->rule_zones ?? [])->unique();
        $missing = $client->reject(fn (array $zone) => $covered->contains($zone['id']));

        return $part + ['ok' => $missing->isEmpty(), 'value' => ($client->count() - $missing->count()).' of '.$client->count(),
            'detail' => $missing->isEmpty() ? 'Every zone with client networks is covered.' : 'Not covered: '.$missing->pluck('name')->join(', ').'.'];
    }
}
