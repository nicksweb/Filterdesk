<?php

namespace App\Services\Policy;

use App\Models\FilterPolicy;
use App\Models\NetworkAssignment;
use App\Models\NetworkType;
use App\Services\Network\FilterSchedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Learns how a controller's content filters are organised, from what it
 * already reports: which filters are site-wide layers (on nearly every
 * network), which are group filters for one type of user (with a schedule
 * or not), which are school-hours layers of another group filter, which are
 * blocklist layers, which networks are user networks and
 * which are device or infrastructure networks, and what looks out of place.
 * Everything here is a suggestion an admin confirms; nothing is changed.
 */
class ControllerLearner
{
    /** Share of networks a filter must be on to count as site-wide. */
    private const SITEWIDE_SHARE = 0.6;

    private const DEVICE_WORDS = ['mgmt', 'management', 'manage', 'infra', 'infrastructure', 'infrastrucure', 'ap', 'aps', 'server', 'servers', 'ilo', 'drac', 'idrac', 'cctv', 'camera', 'cameras',
        'printer', 'printers', 'print', 'phone', 'phones', 'voip', 'crestron', 'av', 'routing', 'router', 'switch', 'switches', 'iot', 'debug', 'test', 'packetfence', 'kitchen',
        'retired', 'appletv', 'directnet', 'default', 'firewall', 'hvac', 'bms', 'security', 'door', 'access-control', 'nvr', 'ups', 'pdu', 'storage', 'nas', 'backup',
        'isp', 'wan', 'uplink', 'transit', 'peering', 'dmz', 'mpls', 'handoff', 'carrier', 'internet', 'nbn', 'fibre', 'fiber'];

    private const USER_WORDS = ['user', 'users', 'staff', 'student', 'students', 'wifi', 'wireless', 'byo', 'byod', 'guest', 'guests', 'visitor', 'visitors', 'boarding', 'contractor', 'contractors',
        'lab', 'labs', 'class', 'classroom', 'eduroam', 'teacher', 'teachers', 'learner', 'pupil', 'vpn', 'wg', 'library'];

    public function __construct(private readonly ControllerSync $sync, private readonly ControllerImporter $importer) {}

    /**
     * @param  array<string, mixed>  $state  from ControllerSync::state()
     * @return array{filters: Collection<string, array<string, mixed>>, networks: Collection<string, array<string, mixed>>, carrier: ?string}
     */
    public function learn(array $state): array
    {
        if ($state['error']) {
            return ['filters' => collect(), 'networks' => collect(), 'carrier' => null];
        }
        $filters = collect($state['filters']);
        $report = $this->sync->networkReport($state)->keyBy('id');
        $total = max(1, $report->count());

        $learned = $filters->mapWithKeys(function (array $filter) use ($filters, $report, $total): array {
            $count = count($filter['network_ids']);
            $named = (bool) preg_match('/site\s*-?\s*wide|global|whole\s*site|all\s*networks|everyone/i', $filter['name']);
            // A filter whose name says who it is for ("Student - School Time") is a group, however
            // many networks it is on: putting the Students filter on device networks is deliberate.
            $forSomeone = $this->importer->suggestAudience($filter['name'], collect()) !== 'other';
            $sitewide = $named || (! $forSomeone && $count >= 3 && $count / $total >= self::SITEWIDE_SHARE);
            $parent = $sitewide ? null : $this->importer->layerParent($filter, $filters);
            $networks = $report->only($filter['network_ids'])->values();

            return [$filter['id'] => [
                'filter' => $filter,
                'role' => match (true) {
                    $sitewide => 'sitewide',
                    $parent !== null => 'layer',
                    default => 'group',
                },
                'parent' => $parent,
                'audience' => $sitewide ? null : $this->audienceFor($filter['name'], $networks, $count / $total),
                'coverage' => $count.' of '.$total,
                'scheduled' => ($filter['schedule'] ?? 'ALWAYS') !== 'ALWAYS',
                'when' => $filter['schedule_label'] ?? FilterSchedule::label($filter['schedule'] ?? null),
                'carries_blocks' => false,
                'paused' => ! ($filter['enabled'] ?? true),
            ]];
        });

        // A scheduled group filter next to an always-on one of the same type ("Student - School Time"
        // and "Students - All Hours") is a school-hours layer of it: both filter that type, one only
        // while UniFi's schedule is on.
        // A filter already linked as a school-hours layer stays one.
        $hoursLinked = FilterPolicy::query()->where('layer_kind', FilterPolicy::HOURS)->whereNotNull('provider_reference')->with('layerOf')->get()->keyBy('provider_reference');
        $learned = $learned->map(function (array $item) use ($learned, $hoursLinked, $filters): array {
            $linkedLayer = $hoursLinked->get($item['filter']['id']);
            if ($linkedLayer && filled($linkedLayer->layerOf?->provider_reference)) {
                $parentFilter = $filters->firstWhere('id', $linkedLayer->layerOf->provider_reference);

                return ['role' => 'hours', 'parent' => $linkedLayer->layerOf->provider_reference, 'parent_name' => $parentFilter['name'] ?? $linkedLayer->layerOf->name, 'audience' => $linkedLayer->audience] + $item;
            }
            if ($item['role'] !== 'group' || ! $item['scheduled']) {
                return $item;
            }
            $parent = $learned->first(fn (array $other) => $other['role'] === 'group' && ! $other['scheduled'] && ! $other['paused'] && $other['audience'] === $item['audience']);

            return $parent ? ['role' => 'hours', 'parent' => $parent['filter']['id'], 'parent_name' => $parent['filter']['name']] + $item : $item;
        });

        // One site-wide layer takes the site-wide blocks: a block-only one, else one named for
        // blocking; the plainest name wins ("Block (Sitewide)" over "Block (Sitewide) Security - …").
        $carrier = $learned->filter(fn (array $item) => $item['role'] === 'sitewide' && ! $item['paused'])
            ->filter(fn (array $item) => Str::contains(Str::lower($item['filter']['name']), 'block') || ($item['filter']['allow'] === [] && $item['filter']['block'] !== []))
            ->sortBy(fn (array $item) => [Str::contains(Str::lower($item['filter']['name']), 'block') ? 0 : 1, strlen($item['filter']['name'])])
            ->keys()->first();
        if ($carrier !== null) {
            $learned = $learned->map(fn (array $item, string $id) => ['carries_blocks' => $id === $carrier] + $item);
        }

        return ['filters' => $learned, 'networks' => $this->networks($report, $learned), 'carrier' => $carrier];
    }

    /**
     * Each network as a user or device network, the groups that filter it,
     * and advice when something looks out of place.
     *
     * @param  Collection<string, array<string, mixed>>  $report
     * @param  Collection<string, array<string, mixed>>  $learned
     * @return Collection<string, array<string, mixed>>
     */
    private function networks(Collection $report, Collection $learned): Collection
    {
        $assignments = NetworkAssignment::query()->with('filterPolicy')->get()->keyBy('provider_network_id');
        $policies = FilterPolicy::query()->main()->whereNotNull('provider_reference')->get()->keyBy('provider_reference');

        return $report->map(function (array $network) use ($learned, $assignments, $policies): array {
            $attached = collect($network['attached'])->filter(fn (array $filter) => $filter['enabled'] ?? true);
            $groups = $attached->filter(fn (array $filter) => in_array($learned->get($filter['id'])['role'] ?? 'group', ['group', 'hours'], true));
            $types = $groups->map(fn (array $filter) => $policies->get($filter['id'])?->audience ?? $learned->get($filter['id'])['audience'] ?? 'other')->unique()->values();
            $sitewide = $attached->filter(fn (array $filter) => ($learned->get($filter['id'])['role'] ?? null) === 'sitewide');
            $assignment = $assignments->get($network['id']);
            $kind = $assignment?->role ?? $this->kindFor($network['name'], $groups->isNotEmpty());
            $studentLevel = $types->contains('student');

            $advice = null;
            if ($kind === 'user' && $groups->isEmpty()) {
                $advice = ['level' => 'warn', 'action' => 'protect', 'text' => $sitewide->isEmpty()
                    ? 'A user network with no filter at all.'
                    : 'A user network with only site-wide filtering: no Staff, Students or Guest filter is on it in UniFi.'];
            } elseif ($groups->isNotEmpty() && $groups->every(fn (array $filter) => ($learned->get($filter['id'])['role'] ?? null) === 'hours')) {
                // Only a school-hours filter: outside its hours, only site-wide filtering applies here.
                $hours = $learned->get($groups->first()['id']);
                $advice = ['level' => 'info', 'action' => 'add_filter', 'filter_id' => $hours['parent'], 'filter_name' => $hours['parent_name'],
                    'text' => NetworkType::labelFor($hours['audience']).' filtering only in school hours ('.$groups->pluck('name')->join(', ').': '.$hours['when'].'). Outside those hours only site-wide filters apply. Add '.$hours['parent_name'].' to filter it at every hour.'];
            } elseif ($kind === 'device' && ! $studentLevel && $attached->isEmpty()) {
                // A device network (cameras, phones, management…) with site-wide filtering is as intended:
                // only one with no filter at all gets the suggestion.
                $advice = ['level' => 'info', 'action' => 'protect', 'text' => 'A device network with no filter in UniFi. Add the baseline filter, or allow it unfiltered and say why.'];
            }
            $mapped = $assignment?->filterPolicy;
            if (! $advice && $mapped && $types->isNotEmpty() && ! $types->contains($mapped->audience)) {
                $match = $policies->toBase()->only($groups->pluck('id')->all())->first();
                $advice = ['level' => 'warn', 'action' => $match ? 'remap' : null, 'policy' => $match,
                    'text' => 'Mapped to '.$mapped->name.' ('.NetworkType::labelFor($mapped->audience).') here, but filtered as '.$types->map(fn ($type) => NetworkType::labelFor($type))->join(' and ').' in UniFi.'];
            }

            return [
                'network' => $network, 'kind' => $kind, 'kind_confirmed' => $assignment?->role !== null,
                'groups' => $groups->pluck('name')->values()->all(), 'types' => $types->all(),
                'sitewide' => $sitewide->pluck('name')->values()->all(), 'advice' => $advice,
            ];
        });
    }

    /** A user network (people's devices) or a device network (infrastructure, cameras, phones…), from its name. */
    public function kindFor(string $name, bool $hasGroupFilter): string
    {
        $words = preg_split('/[^a-z0-9]+/', Str::lower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $joined = implode('', $words);
        $has = fn (array $list): bool => collect($list)->contains(fn (string $word) => in_array($word, $words, true) || (strlen($word) > 5 && str_contains($joined, $word)));

        return match (true) {
            $has(self::DEVICE_WORDS) => 'device',
            $has(self::USER_WORDS) => 'user',
            default => $hasGroupFilter ? 'user' : 'device',
        };
    }

    /**
     * The network type a group filter is for: from its name first ("Allow
     * (Staff)" is Staff), then from its networks' names when it is on only
     * a few of them.
     */
    private function audienceFor(string $name, Collection $networks, float $share): string
    {
        $fromName = $this->importer->suggestAudience($name, collect());
        if ($fromName !== 'other' || $share > 0.5) {
            return $fromName;
        }

        return $this->importer->suggestAudience($name, $networks->map(fn (array $network) => ['name' => $network['name']]));
    }
}
