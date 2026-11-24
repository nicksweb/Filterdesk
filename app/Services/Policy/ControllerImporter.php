<?php

namespace App\Services\Policy;

use App\Enums\EntrySource;
use App\Enums\ListKind;
use App\Models\FilterPolicy;
use App\Models\NetworkAssignment;
use App\Models\NetworkType;
use App\Models\PolicyEntry;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Brings a controller's content filters in as policies, ready to use:
 * suggests how each filter should be set up (link an unlinked policy of the
 * same audience, create one, or treat it as another filter's layer), then
 * applies those choices with every allow and block entry imported, networks
 * mapped and categories taken as they are, so the first push changes nothing.
 */
class ControllerImporter
{
    private const CHUNK = 1000;

    /** Names that mark a filter as a layer of blocklist feeds. */
    private const LAYER_WORDS = ['blocklist', 'block list', 'layer', 'feeds', 'hagezi'];

    public function __construct(
        private readonly ControllerSync $sync,
        private readonly PolicyEngine $engine,
        private readonly PolicyListParser $parser,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * A suggested choice per controller filter id.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, array{action: string, policy_id: ?int, audience: string, import: bool, parent: ?string, carries_blocks: bool}>
     */
    public function suggest(array $state): array
    {
        $filters = collect($state['filters']);
        $networks = $state['error'] ? collect() : $this->sync->networkReport($state);
        $policies = FilterPolicy::query()->ordered()->get();
        $suggestions = [];

        $learned = app(ControllerLearner::class)->learn($state)['filters'];

        // Parents before layers (blocklist and school-hours ones), so a layer can name the parent's choice.
        $layers = $filters->mapWithKeys(fn (array $filter) => [$filter['id'] => $this->layerParent($filter, $filters)])->filter();
        $hours = $learned->filter(fn (array $lesson) => $lesson['role'] === 'hours')->map(fn (array $lesson) => $lesson['parent']);

        foreach ($filters->sortBy(fn (array $filter) => isset($layers[$filter['id']]) || isset($hours[$filter['id']]) ? 1 : 0) as $filter) {
            $linked = $policies->firstWhere('provider_reference', $filter['id']);
            $lesson = $learned->get($filter['id']);
            $audience = $lesson['audience'] ?? $this->suggestAudience($filter['name'], $networks->whereIn('id', $filter['network_ids']));
            $parent = $layers[$filter['id']] ?? null;
            $sitewide = ($lesson['role'] ?? null) === 'sitewide';
            $carries = (bool) ($lesson['carries_blocks'] ?? false);

            if ($linked && isset($hours[$filter['id']]) && ! $linked->isHoursLayer()) {
                // Brought in earlier as a policy of its own: it becomes the school-hours layer.
                $suggestions[$filter['id']] = ['action' => 'hours', 'policy_id' => $linked->id, 'audience' => $linked->audience, 'import' => false, 'parent' => $hours[$filter['id']], 'carries_blocks' => false];

                continue;
            }
            if ($linked) {
                $suggestions[$filter['id']] = ['action' => $sitewide && ! $linked->isLayer() ? 'sitewide' : 'link', 'policy_id' => $linked->id, 'audience' => $sitewide ? $linked->audience : ($linked->isSitewide() ? $audience : $linked->audience),
                    'import' => false, 'parent' => $linked->layerOf?->provider_reference, 'carries_blocks' => $sitewide ? $carries : false];

                continue;
            }
            if ($sitewide) {
                $spare = $this->spare($filter['name'], null, array_column($suggestions, 'policy_id'), $policies);
                $suggestions[$filter['id']] = ['action' => 'sitewide', 'policy_id' => $spare?->id, 'audience' => 'other', 'import' => true, 'parent' => null, 'carries_blocks' => $carries];

                continue;
            }
            if (isset($hours[$filter['id']])) {
                $suggestions[$filter['id']] = ['action' => 'hours', 'policy_id' => null, 'audience' => $audience, 'import' => true, 'parent' => $hours[$filter['id']], 'carries_blocks' => false];

                continue;
            }
            if ($parent) {
                $suggestions[$filter['id']] = ['action' => 'layer', 'policy_id' => null, 'audience' => $audience, 'import' => true, 'parent' => $parent, 'carries_blocks' => false];

                continue;
            }

            // Reuse an unlinked policy: one with the same name first (made by the installer or an
            // earlier import), then one with the same network type, such as the default Students and Staff.
            $spare = $this->spare($filter['name'], $audience, array_column($suggestions, 'policy_id'), $policies);
            $audience = $spare && Str::slug($spare->name) === Str::slug($filter['name']) ? $audience : ($spare?->audience ?? $audience);
            $suggestions[$filter['id']] = $spare
                ? ['action' => 'link', 'policy_id' => $spare->id, 'audience' => $audience, 'import' => true, 'parent' => null, 'carries_blocks' => false]
                : ['action' => 'create', 'policy_id' => null, 'audience' => $audience, 'import' => true, 'parent' => null, 'carries_blocks' => false];
        }

        return $suggestions;
    }

    /**
     * Apply choices (from suggest() or the setup form). Returns one line per filter.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, array<string, mixed>>  $choices
     * @return list<string>
     */
    public function apply(array $state, array $choices, ?User $actor): array
    {
        $filters = collect($state['filters'])->keyBy('id');
        $done = [];
        $policyFor = [];

        // Parents first; a layer needs its parent's policy.
        $ordered = collect($choices)->sortBy(fn (array $choice) => in_array($choice['action'] ?? '', ['layer', 'hours'], true) ? 1 : 0);
        foreach ($ordered as $filterId => $choice) {
            $filter = $filters->get((string) $filterId);
            $action = $choice['action'] ?? null;
            if (! $filter || $action === null) {
                continue;
            }
            if ($action === 'skip') {
                // Leave it alone: a policy that was managing it lets go (its lists stay; Clean up offers to delete it).
                foreach (FilterPolicy::query()->where('provider_reference', $filter['id'])->get() as $linked) {
                    $linked->update(['provider_reference' => null, 'provider_name' => null, 'sync_status' => null]);
                    NetworkAssignment::query()->where('filter_policy_id', $linked->id)->update(['filter_policy_id' => null]);
                    $this->audit->log('policy.filter_unlinked', $linked, ['filter' => $filter['name']]);
                    $done[] = "{$filter['name']}: left alone; {$linked->name} no longer manages it";
                }

                continue;
            }

            if ($action === 'layer') {
                $parentPolicy = $policyFor[$choice['parent'] ?? ''] ?? FilterPolicy::query()->main()->where('provider_reference', $choice['parent'] ?? '')->first();
                if (! $parentPolicy) {
                    $done[] = "{$filter['name']}: skipped, its parent filter was not set up";

                    continue;
                }
                $policy = $parentPolicy->layer ?? FilterPolicy::query()->create([
                    'name' => $filter['name'], 'slug' => $this->engine->uniqueSlug($filter['name']), 'audience' => $parentPolicy->audience,
                    'layer_of_id' => $parentPolicy->id, 'description' => "Carries the blocklist feeds for {$parentPolicy->name}.",
                    'sort_order' => $parentPolicy->sort_order,
                ]);
            } elseif ($action === 'hours') {
                $parentPolicy = $policyFor[$choice['parent'] ?? ''] ?? FilterPolicy::query()->main()->where('provider_reference', $choice['parent'] ?? '')->first();
                if (! $parentPolicy) {
                    $done[] = "{$filter['name']}: skipped, its all-hours filter was not set up";

                    continue;
                }
                $policy = $this->hoursLayer($parentPolicy, $filter);
            } elseif ($action === 'sitewide') {
                $policy = (filled($choice['policy_id'] ?? null) ? FilterPolicy::query()->main()->find($choice['policy_id']) : null)
                    ?? $this->spare($filter['name'], null, array_map(fn (FilterPolicy $policy) => $policy->id, $policyFor))
                    ?? FilterPolicy::query()->create([
                        'name' => $filter['name'], 'slug' => $this->engine->uniqueSlug($filter['name']), 'audience' => NetworkType::byKey('other') ? 'other' : NetworkType::query()->ordered()->value('key'),
                        'description' => "Site-wide layer: content filter {$filter['name']} in the controller.",
                    ]);
                $carries = ! empty($choice['carries_blocks']);
                if ($carries) {
                    FilterPolicy::query()->whereKeyNot($policy->id)->update(['receives_site_blocks' => false]);
                }
                $policy->update(['role' => 'sitewide', 'receives_site_blocks' => $carries]);
                // A site-wide layer is on every network; networks belong to their group policies.
                NetworkAssignment::query()->where('filter_policy_id', $policy->id)->update(['filter_policy_id' => null]);
            } elseif ($action === 'link' && filled($choice['policy_id'] ?? null)) {
                $policy = FilterPolicy::query()->findOrFail($choice['policy_id']);
                $audience = NetworkType::byKey((string) ($choice['audience'] ?? ''))?->key;
                $policy->update(['role' => 'group', 'receives_site_blocks' => false] + ($audience && ! $policy->isLayer() ? ['audience' => $audience] : []));
                if ($audience) {
                    $this->engine->ensureAudienceLists($audience);
                }
            } elseif ($existing = $this->spare($filter['name'], null, array_map(fn (FilterPolicy $policy) => $policy->id, $policyFor))) {
                // Never a second policy of the same name: an unlinked one is linked instead, with the chosen type.
                $policy = $existing;
                $audience = NetworkType::byKey((string) ($choice['audience'] ?? ''))?->key;
                if ($audience && $audience !== $policy->audience) {
                    $policy->update(['audience' => $audience]);
                    $this->engine->ensureAudienceLists($audience);
                }
            } else {
                $audience = NetworkType::byKey((string) ($choice['audience'] ?? ''))?->key ?? 'other';
                $policy = FilterPolicy::query()->create([
                    'name' => $filter['name'], 'slug' => $this->engine->uniqueSlug($filter['name']), 'audience' => $audience,
                    'description' => "Content filter {$filter['name']} in the controller.",
                ]);
                $this->engine->ensureAudienceLists($audience);
            }

            $imported = ! empty($choice['import']) ? $this->importEntries($policy, $filter, $actor) : 0;
            $runTogether = collect([...$filter['allow'], ...$filter['block']])->filter(fn (string $domain) => RunTogetherEntries::split($domain) !== null)->values();
            if ($runTogether->isNotEmpty()) {
                $this->notes[] = "{$filter['name']} has ".($runTogether->count() === 1 ? 'an entry that looks' : $runTogether->count().' entries that look').' like two hostnames run together ('.$runTogether->take(3)->join(', ').'): split or keep it on the policy page';
            }
            $policy->refresh();
            $this->sync->link($filter, $policy, $actor, remapNetworks: ! $policy->isLayer() && ! $policy->isSitewide());
            $policyFor[$filter['id']] = $policy;
            $this->audit->log('policy.setup_from_controller', $policy, ['filter' => $filter['name'], 'imported' => $imported, 'action' => $action]);
            $done[] = "{$filter['name']} → {$policy->name}".($policy->isHoursLayer() ? " (school-hours layer of {$policy->layerOf->name})" : ($policy->isLayer() ? " (layer of {$policy->layerOf->name})" : ($policy->isSitewide() ? ' (site-wide layer'.($policy->receives_site_blocks ? ', takes the site-wide blocks' : '').')' : ''))).($imported ? ' ('.number_format($imported).' entries imported)' : '');
        }
        $this->sync->forget();

        return [...$done, ...$this->notes];
    }

    /**
     * The school-hours layer of a policy for this controller filter. A policy
     * already linked to the filter (brought in earlier as a policy of its own)
     * becomes the layer, keeping its lists; its networks move to the parent.
     *
     * @param  array<string, mixed>  $filter
     */
    public function hoursLayer(FilterPolicy $parent, array $filter): FilterPolicy
    {
        $attributes = ['layer_of_id' => $parent->id, 'layer_kind' => FilterPolicy::HOURS, 'audience' => $parent->audience, 'role' => 'group', 'receives_site_blocks' => false,
            'description' => "School-hours layer of {$parent->name}: content filter {$filter['name']} in the controller, on its own schedule."];
        $existing = FilterPolicy::query()->whereKeyNot($parent->id)->where('provider_reference', $filter['id'])->first()
            ?? $parent->hoursLayer;
        if ($existing && ! $existing->isLayer()) {
            NetworkAssignment::query()->where('filter_policy_id', $existing->id)->update(['filter_policy_id' => $parent->id]);
        }
        // Its networks are the parent's type, at least in school hours.
        NetworkAssignment::query()->whereIn('provider_network_id', $filter['network_ids'])->whereNull('filter_policy_id')->update(['filter_policy_id' => $parent->id]);
        if ($existing) {
            $wasMain = ! $existing->isLayer();
            $existing->update($attributes);
            if ($wasMain) {
                $this->handOver($existing->refresh(), $parent->refresh(), $filter);
            }

            return $existing->refresh();
        }

        return FilterPolicy::query()->create($attributes + ['name' => $filter['name'], 'slug' => $this->engine->uniqueSlug($filter['name']), 'sort_order' => $parent->sort_order]);
    }

    /** What converting a policy into a school-hours layer also changed, for the result message. */
    public array $notes = [];

    /**
     * A policy that becomes a school-hours layer hands its place to the
     * parent: the probe profile moves to the parent, and when it had a
     * general name ("Students") while the parent is named after its UniFi
     * filter, the names swap, so the main policy keeps the main name and the
     * layer is named after its filter.
     *
     * @param  array<string, mixed>  $filter
     */
    private function handOver(FilterPolicy $layer, FilterPolicy $parent, array $filter): void
    {
        if ($layer->network_profile_id && ! $parent->network_profile_id) {
            $parent->update(['network_profile_id' => $layer->network_profile_id]);
            $layer->update(['network_profile_id' => null]);
            $this->notes[] = "The probe profile moved from {$layer->name} to {$parent->name}.";
        }
        $named = fn (FilterPolicy $policy, ?string $filterName) => $filterName !== null && Str::slug($policy->name) === Str::slug($filterName);
        if (! $named($layer, $filter['name']) && $named($parent, $parent->provider_name)) {
            $general = $layer->name;
            $layer->update(['name' => $filter['name'], 'slug' => $this->engine->uniqueSlug($filter['name'])]);
            $parent->update(['name' => $general, 'slug' => $this->engine->uniqueSlug($general)]);
            $this->notes[] = "Renamed: {$general} is now the all-hours policy ({$parent->provider_name}), and the layer is named {$filter['name']}.";
        }
    }

    /**
     * An unlinked main policy to reuse for a controller filter: the one with
     * the same name (ignoring case and punctuation), else, when a network
     * type is given, the first of that type.
     *
     * @param  list<int|null>  $taken  policy ids already chosen for other filters
     * @param  Collection<int, FilterPolicy>|null  $policies
     */
    public function spare(string $name, ?string $audience, array $taken = [], ?Collection $policies = null): ?FilterPolicy
    {
        $free = ($policies ?? FilterPolicy::query()->ordered()->get())
            ->filter(fn (FilterPolicy $policy) => ! $policy->isLayer() && blank($policy->provider_reference) && ! in_array($policy->id, $taken, true));
        $key = fn (string $value): string => Str::slug($value);

        return $free->first(fn (FilterPolicy $policy) => $key($policy->name) === $key($name))
            ?? ($audience !== null ? $free->first(fn (FilterPolicy $policy) => $policy->audience === $audience) : null);
    }

    /**
     * The filter this one is a layer of: it shares all its networks with
     * exactly one other filter and is named or shaped like a blocklist
     * (no allows, more blocks than the other filter).
     *
     * @param  array<string, mixed>  $filter
     * @param  Collection<int, array<string, mixed>>  $filters
     */
    public function layerParent(array $filter, Collection $filters): ?string
    {
        if ($filter['network_ids'] === []) {
            return null;
        }
        $partners = $filters->filter(fn (array $other) => $other['id'] !== $filter['id'] && array_diff($filter['network_ids'], $other['network_ids']) === []);
        if ($partners->count() !== 1) {
            return null;
        }
        $parent = $partners->first();
        $named = Str::contains(Str::lower($filter['name']), self::LAYER_WORDS) && ! Str::contains(Str::lower($parent['name']), self::LAYER_WORDS);
        $shaped = $filter['allow'] === [] && count($filter['block']) > max(1000, count($parent['block']));

        return $named || $shaped ? $parent['id'] : null;
    }

    /**
     * Import a filter's entries into lists owned by that policy only, in
     * bulk. A blocklist layer only takes blocks; a school-hours layer keeps both.
     *
     * @param  array<string, mixed>  $filter
     */
    public function importEntries(FilterPolicy $policy, array $filter, ?User $actor): int
    {
        $count = 0;
        $kinds = $policy->isLayer() && ! $policy->isHoursLayer() ? [[ListKind::Block, $filter['block']]] : [[ListKind::Allow, $filter['allow']], [ListKind::Block, $filter['block']]];
        foreach ($kinds as [$kind, $entries]) {
            $domains = $this->parser->parse(implode("\n", $entries));
            if ($domains === []) {
                continue;
            }
            $list = $this->engine->ownList($policy, $kind, "Imported from the controller filter {$filter['name']}.");
            $now = now();
            foreach (array_chunk($domains, self::CHUNK) as $chunk) {
                $count += PolicyEntry::query()->insertOrIgnore(array_map(fn (string $domain) => [
                    'policy_list_id' => $list->id, 'domain' => $domain, 'source' => EntrySource::Import->value, 'added_by_id' => $actor?->id,
                    'note' => "Imported from {$filter['name']}", 'created_at' => $now, 'updated_at' => $now,
                ], $chunk));
            }
        }

        return $count;
    }

    /**
     * Guess a network type from the filter and network names: a type whose
     * key or name appears in them, otherwise common words, otherwise "other".
     */
    public function suggestAudience(string $name, Collection $networks): string
    {
        $text = Str::lower($name.' '.$networks->pluck('name')->join(' '));
        foreach (NetworkType::query()->ordered()->get() as $type) {
            if ($type->key !== 'other' && (Str::contains($text, Str::lower($type->key)) || Str::contains($text, Str::lower(Str::singular($type->name))))) {
                return $type->key;
            }
        }

        $guess = match (true) {
            Str::contains($text, ['student', 'pupil', 'learner', 'junior', 'senior', 'boarding', 'byod']) => 'student',
            Str::contains($text, ['guest', 'visitor', 'public', 'untrusted']) => 'guest',
            Str::contains($text, ['staff', 'teacher', 'admin', 'corporate', 'employee', 'faculty']) => 'staff',
            default => 'other',
        };

        return NetworkType::byKey($guess) ? $guess : 'other';
    }
}
