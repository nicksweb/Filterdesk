<?php

namespace App\Services\Policy;

use App\Models\FilterPolicy;
use App\Models\NetworkAssignment;
use App\Models\NetworkType;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Learns how a controller's content filters are organised, from what it
 * already reports: which filters are site-wide layers (on nearly every
 * network), which are group filters for one type of user (with a schedule
 * or not), which are blocklist layers, which networks are user networks and
 * which are device or infrastructure networks, and what looks out of place.
 * Everything here is a suggestion an admin confirms; nothing is changed.
 */
class ControllerLearner
{
    /** Share of networks a filter must be on to count as site-wide. */
    private const SITEWIDE_SHARE = 0.6;

    private const DEVICE_WORDS = ['mgmt', 'management', 'manage', 'infra', 'infrastructure', 'infrastrucure', 'ap', 'aps', 'server', 'servers', 'ilo', 'drac', 'idrac', 'cctv', 'camera', 'cameras',
        'printer', 'printers', 'print', 'phone', 'phones', 'voip', 'crestron', 'av', 'routing', 'router', 'switch', 'switches', 'iot', 'debug', 'test', 'packetfence', 'kitchen',
        'retired', 'appletv', 'directnet', 'default', 'firewall', 'hvac', 'bms', 'security', 'door', 'access-control', 'nvr', 'ups', 'pdu', 'storage', 'nas', 'backup'];

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
            $sitewide = $named || ($count >= 3 && $count / $total >= self::SITEWIDE_SHARE);
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
                'carries_blocks' => false,
                'paused' => ! ($filter['enabled'] ?? true),
            ]];
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
            $groups = $attached->filter(fn (array $filter) => in_array($learned->get($filter['id'])['role'] ?? 'group', ['group'], true));
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
            } elseif ($kind === 'device' && ! $studentLevel) {
                $advice = ['level' => 'info', 'action' => 'protect', 'text' => 'A device network without Students filtering. Recommended: add it, so anyone who gets onto this network is filtered.'];
            }
            $mapped = $assignment?->filterPolicy;
            if (! $advice && $mapped && $types->isNotEmpty() && ! $types->contains($mapped->audience)) {
                $match = $policies->only($groups->pluck('id')->all())->first();
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
