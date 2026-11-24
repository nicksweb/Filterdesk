<?php

namespace App\Services\Policy;

use App\Models\FilterPolicy;
use App\Models\NetworkAssignment;
use App\Models\PolicyEntry;
use App\Models\PolicyList;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Merges a duplicate policy into another (for example a second "Staff"
 * made by an earlier import): its own lists' entries move to the matching
 * lists of the policy that stays, its networks and layer move across, and
 * the controller link and probe profile move too when the one that stays
 * has none. Then the duplicate is deleted. Nothing changes in UniFi.
 */
class PolicyMerger
{
    private const LINK_FIELDS = ['provider_reference', 'provider_name', 'current_allowlist', 'current_blocklist', 'snapshot_at', 'snapshot_by_id',
        'sync_status', 'sync_checked_at', 'sync_details', 'sync_baseline', 'controller_exclusions', 'controller_managed'];

    public function __construct(private readonly ControllerSync $sync, private readonly AuditLogger $audit) {}

    /** @return array{entries: int, lists: int, networks: int} */
    public function merge(FilterPolicy $from, FilterPolicy $into, ?User $actor): array
    {
        if ($from->is($into)) {
            throw new InvalidArgumentException('Choose a different policy to merge into.');
        }
        if ($from->isLayer() || $into->isLayer()) {
            throw new InvalidArgumentException('Layers cannot be merged; merge the policies they belong to.');
        }
        if (filled($from->provider_reference) && filled($into->provider_reference)) {
            throw new InvalidArgumentException("Both are linked to UniFi filters ({$from->provider_name} and {$into->provider_name}). Unlink one first, or keep them apart.");
        }
        if ($from->layer && $into->layer) {
            throw new InvalidArgumentException('Both have a blocklist layer. Delete one layer first.');
        }
        if ($from->hoursLayer && $into->hoursLayer) {
            throw new InvalidArgumentException('Both have a school-hours layer. Unlink one first.');
        }

        $result = DB::transaction(function () use ($from, $into): array {
            $moved = 0;
            $lists = 0;
            foreach (PolicyList::query()->where('filter_policy_id', $from->id)->with('entries')->get() as $list) {
                // The matching list of the policy that stays: same kind, and same name or both its default list.
                $target = PolicyList::query()->where('filter_policy_id', $into->id)->get()
                    ->first(fn (PolicyList $other) => $other->kind === $list->kind && ($other->name === $list->name || ($other->is_default && $list->is_default)));
                if (! $target) {
                    $list->update(['filter_policy_id' => $into->id]);
                    $lists++;

                    continue;
                }
                $existing = PolicyEntry::query()->where('policy_list_id', $target->id)->pluck('domain')->all();
                foreach ($list->entries as $entry) {
                    if (in_array($entry->domain, $existing, true)) {
                        continue;
                    }
                    $entry->update(['policy_list_id' => $target->id]);
                    $moved++;
                }
                $list->delete();
            }
            $networks = NetworkAssignment::query()->where('filter_policy_id', $from->id)->update(['filter_policy_id' => $into->id]);
            $from->layer?->update(['layer_of_id' => $into->id, 'audience' => $into->audience]);
            $from->hoursLayer?->update(['layer_of_id' => $into->id, 'audience' => $into->audience]);

            $carry = [];
            if (blank($into->provider_reference) && filled($from->provider_reference)) {
                $carry = $from->only(self::LINK_FIELDS);
            }
            if (! $into->network_profile_id && $from->network_profile_id) {
                $carry['network_profile_id'] = $from->network_profile_id;
            }
            $from->forceFill(['provider_reference' => null, 'network_profile_id' => null])->save();
            if ($carry !== []) {
                $into->forceFill($carry)->save();
            }
            $from->delete();

            return ['entries' => $moved, 'lists' => $lists, 'networks' => $networks];
        });

        $this->sync->forget();
        $this->audit->log('policy.merged', $into, ['from' => $from->name.' ('.$from->slug.')'] + $result);

        return $result;
    }
}
