<?php

namespace App\Services\Policy;

use App\Enums\Audience;
use App\Enums\EntrySource;
use App\Enums\ListKind;
use App\Models\FilterPolicy;
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
     * @return array<string, array{action: string, policy_id: ?int, audience: string, import: bool, parent: ?string}>
     */
    public function suggest(array $state): array
    {
        $filters = collect($state['filters']);
        $networks = $state['error'] ? collect() : $this->sync->networkReport($state);
        $policies = FilterPolicy::query()->ordered()->get();
        $suggestions = [];

        // Parents before layers, so a layer can name the parent's choice.
        $layers = $filters->mapWithKeys(fn (array $filter) => [$filter['id'] => $this->layerParent($filter, $filters)])->filter();

        foreach ($filters->sortBy(fn (array $filter) => isset($layers[$filter['id']]) ? 1 : 0) as $filter) {
            $linked = $policies->firstWhere('provider_reference', $filter['id']);
            $audience = $this->suggestAudience($filter['name'], $networks->whereIn('id', $filter['network_ids']));
            $parent = $layers[$filter['id']] ?? null;

            if ($linked) {
                $suggestions[$filter['id']] = ['action' => 'link', 'policy_id' => $linked->id, 'audience' => $linked->audience->value, 'import' => false, 'parent' => $linked->layerOf?->provider_reference];

                continue;
            }
            if ($parent) {
                $suggestions[$filter['id']] = ['action' => 'layer', 'policy_id' => null, 'audience' => $audience->value, 'import' => true, 'parent' => $parent];

                continue;
            }

            // Reuse an unlinked policy with the same audience, such as the default Students and Staff.
            $spare = $policies->first(fn (FilterPolicy $policy) => ! $policy->isLayer() && blank($policy->provider_reference)
                && $policy->audience === $audience && ! in_array($policy->id, array_column($suggestions, 'policy_id'), true));
            $suggestions[$filter['id']] = $spare
                ? ['action' => 'link', 'policy_id' => $spare->id, 'audience' => $audience->value, 'import' => true, 'parent' => null]
                : ['action' => 'create', 'policy_id' => null, 'audience' => $audience->value, 'import' => true, 'parent' => null];
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
        $ordered = collect($choices)->sortBy(fn (array $choice) => ($choice['action'] ?? '') === 'layer' ? 1 : 0);
        foreach ($ordered as $filterId => $choice) {
            $filter = $filters->get((string) $filterId);
            $action = $choice['action'] ?? 'skip';
            if (! $filter || $action === 'skip') {
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
            } elseif ($action === 'link' && filled($choice['policy_id'] ?? null)) {
                $policy = FilterPolicy::query()->findOrFail($choice['policy_id']);
            } else {
                $audience = Audience::tryFrom((string) ($choice['audience'] ?? '')) ?? Audience::Other;
                $policy = FilterPolicy::query()->create([
                    'name' => $filter['name'], 'slug' => $this->engine->uniqueSlug($filter['name']), 'audience' => $audience,
                    'description' => "Content filter {$filter['name']} in the controller.",
                ]);
                $this->engine->ensureAudienceLists($audience);
            }

            $imported = ! empty($choice['import']) ? $this->importEntries($policy, $filter, $actor) : 0;
            $this->sync->link($filter, $policy->refresh(), $actor, remapNetworks: ! $policy->isLayer());
            $policyFor[$filter['id']] = $policy;
            $this->audit->log('policy.setup_from_controller', $policy, ['filter' => $filter['name'], 'imported' => $imported, 'action' => $action]);
            $done[] = "{$filter['name']} → {$policy->name}".($policy->isLayer() ? " (layer of {$policy->layerOf->name})" : '').($imported ? ' ('.number_format($imported).' entries imported)' : '');
        }
        $this->sync->forget();

        return $done;
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
     * bulk. A layer only takes blocks.
     *
     * @param  array<string, mixed>  $filter
     */
    public function importEntries(FilterPolicy $policy, array $filter, ?User $actor): int
    {
        $count = 0;
        $kinds = $policy->isLayer() ? [[ListKind::Block, $filter['block']]] : [[ListKind::Allow, $filter['allow']], [ListKind::Block, $filter['block']]];
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

    /** Guess an audience from the filter and network names. */
    public function suggestAudience(string $name, Collection $networks): Audience
    {
        $text = Str::lower($name.' '.$networks->pluck('name')->join(' '));

        return match (true) {
            Str::contains($text, ['student', 'pupil', 'learner', 'junior', 'senior', 'boarding', 'byod']) => Audience::Student,
            Str::contains($text, ['guest', 'visitor', 'public', 'untrusted']) => Audience::Guest,
            Str::contains($text, ['staff', 'teacher', 'admin', 'corporate', 'employee', 'faculty']) => Audience::Staff,
            default => Audience::Other,
        };
    }
}
