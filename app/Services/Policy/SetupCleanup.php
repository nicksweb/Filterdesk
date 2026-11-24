<?php

namespace App\Services\Policy;

use App\Enums\EntrySource;
use App\Models\FilterPolicy;
use App\Models\NetworkAssignment;
use App\Models\NetworkProfile;
use App\Models\PolicyEntry;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;

/**
 * Leftovers from earlier set-up runs: policies that no longer manage a
 * UniFi filter and hold nothing of their own but imported entries (no
 * requests, nothing added by hand, no networks, no layers), and switched-off
 * probe profiles that no managed policy uses, or that sit on a VPN with no
 * VLAN to probe. Offered in Guided setup to delete in one go.
 */
class SetupCleanup
{
    private const BUILT_IN_PROFILES = ['student', 'staff'];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{policies: Collection<int, FilterPolicy>, profiles: Collection<int, NetworkProfile>} */
    public function candidates(): array
    {
        $bulk = [EntrySource::Import->value, EntrySource::Feed->value];
        $policies = FilterPolicy::query()->main()->whereNull('provider_reference')->withCount('networkAssignments')->ordered()->get()
            ->filter(fn (FilterPolicy $policy) => $policy->network_assignments_count === 0
                && ! FilterPolicy::query()->where('layer_of_id', $policy->id)->exists()
                && ! PolicyEntry::query()->whereHas('policyList', fn ($query) => $query->where('filter_policy_id', $policy->id))->whereNotIn('source', $bulk)->exists())
            ->values();

        $used = FilterPolicy::query()->whereNotNull('provider_reference')->where('enabled', true)->whereNotNull('network_profile_id')->pluck('network_profile_id')->all();
        $profiles = NetworkProfile::query()->where('enabled', false)->whereNotIn('key', self::BUILT_IN_PROFILES)->orderBy('name')->get()
            ->filter(function (NetworkProfile $profile) use ($used): bool {
                $network = filled($profile->unifi_network_id) ? NetworkAssignment::query()->where('provider_network_id', $profile->unifi_network_id)->first() : null;

                return ! in_array($profile->id, $used, true) || ($network && ProbePlanner::isVpn($network));
            })->values();

        return ['policies' => $policies, 'profiles' => $profiles];
    }

    /**
     * Delete the chosen leftovers (only ones that are still candidates).
     *
     * @param  list<int>  $policyIds
     * @param  list<int>  $profileIds
     * @return list<string> what was deleted
     */
    public function clean(array $policyIds, array $profileIds, User $actor): array
    {
        $candidates = $this->candidates();
        $deleted = [];
        foreach ($candidates['policies']->whereIn('id', $policyIds) as $policy) {
            $this->audit->log('policy.deleted', $policy, ['name' => $policy->name, 'reason' => 'setup clean-up']);
            $deleted[] = "policy {$policy->name}";
            $policy->delete();
        }
        foreach ($candidates['profiles']->whereIn('id', $profileIds) as $profile) {
            FilterPolicy::query()->where('network_profile_id', $profile->id)->update(['network_profile_id' => null]);
            $this->audit->log('network_profile.deleted', null, ['name' => $profile->name, 'reason' => 'setup clean-up']);
            $deleted[] = "probe profile {$profile->name}";
            $profile->delete();
        }

        return $deleted;
    }
}
