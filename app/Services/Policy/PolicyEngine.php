<?php

namespace App\Services\Policy;

use App\Enums\AccessScope;
use App\Enums\Audience;
use App\Enums\EntrySource;
use App\Enums\ListKind;
use App\Enums\ListScope;
use App\Enums\RequestStatus;
use App\Models\FilterPolicy;
use App\Models\NetworkProfile;
use App\Models\PolicyEntry;
use App\Models\PolicyList;
use App\Models\User;
use App\Models\WebsiteRequest;
use App\Services\Network\DomainRules;
use App\Services\Network\NetworkProviderManager;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Computes what each filter policy *should* contain and compares it with the
 * snapshot last copied from the network provider.
 *
 * Desired allow list for a policy = every effective entry in every allow list
 * that applies to it (site-wide, its audience, and its own lists). Desired
 * block list = the same for block lists, minus anything allowed. Approved
 * requests are materialised as entries in the default allow list of each
 * audience they cover, so manual additions and approvals are handled the same way.
 */
class PolicyEngine
{
    public function __construct(
        private readonly NetworkProviderManager $providers,
        private readonly DomainRecommender $recommender,
    ) {}

    public function rules(): DomainRules
    {
        return $this->providers->domainRules();
    }

    /**
     * Idempotently create the baseline policies and lists. Safe to run on
     * every upgrade.
     */
    public function ensureDefaults(): void
    {
        DB::transaction(function (): void {
            if (FilterPolicy::query()->doesntExist()) {
                foreach (NetworkProfile::query()->orderBy('sort_order')->get() as $profile) {
                    if (! in_array($profile->audience, [Audience::Staff, Audience::Student, Audience::Guest], true)) {
                        continue;
                    }
                    FilterPolicy::query()->create([
                        'name' => $profile->audience === Audience::Student ? 'Students' : ($profile->audience === Audience::Staff ? 'Staff' : 'Guests'),
                        'slug' => $this->uniqueSlug($profile->audience->value),
                        'audience' => $profile->audience,
                        'network_profile_id' => $profile->id,
                        'sort_order' => $profile->sort_order,
                        'description' => "Content filter applied to the {$profile->name} network.",
                    ]);
                }
            }

            $this->ensureList(ListKind::Allow, ListScope::Site, null, 'Site-wide allow', 'Allowed on every network, including students and guests.');
            $this->ensureList(ListKind::Block, ListScope::Site, null, 'Site-wide block', 'Blocked on every network unless allowed by a more specific list.');

            $audiences = FilterPolicy::query()->pluck('audience')->push(Audience::Staff, Audience::Student)->unique(fn ($audience) => $audience->value);
            foreach ($audiences as $audience) {
                $this->ensureAudienceLists($audience);
            }
        });
    }

    public function ensureAudienceLists(Audience $audience): void
    {
        $name = match ($audience) {
            Audience::Staff => 'Staff',
            Audience::Student => 'Student',
            Audience::Guest => 'Guest',
            Audience::Other => 'Other networks',
        };
        $this->ensureList(ListKind::Allow, ListScope::Audience, $audience, "{$name} allow", "Allowed on every {$audience->label()} policy. Approved requests are added here.", true);
        $this->ensureList(ListKind::Block, ListScope::Audience, $audience, "{$name} block", "Blocked on every {$audience->label()} policy.");
    }

    /**
     * The allow list that receives approvals for an audience (or site-wide).
     */
    public function defaultAllowList(?Audience $audience): PolicyList
    {
        if ($audience === null) {
            return $this->ensureList(ListKind::Allow, ListScope::Site, null, 'Site-wide allow', 'Allowed on every network.');
        }

        $list = PolicyList::query()->where('kind', ListKind::Allow)->where('scope', ListScope::Audience)
            ->where('audience', $audience)->orderByDesc('is_default')->orderBy('id')->first();

        if ($list) {
            return $list;
        }

        $this->ensureAudienceLists($audience);

        return $this->defaultAllowList($audience);
    }

    /** The block list that receives block requests for an audience (or site-wide). */
    public function defaultBlockList(?Audience $audience): PolicyList
    {
        if ($audience === null) {
            return $this->ensureList(ListKind::Block, ListScope::Site, null, 'Site-wide block', 'Blocked on every network unless allowed by a more specific list.');
        }

        $list = PolicyList::query()->where('kind', ListKind::Block)->where('scope', ListScope::Audience)
            ->where('audience', $audience)->orderByDesc('is_default')->orderBy('id')->first();
        if ($list) {
            return $list;
        }
        $this->ensureAudienceLists($audience);

        return $this->defaultBlockList($audience);
    }

    /** @return list<PolicyList> */
    public function targetListsFor(AccessScope $scope, ListKind $kind = ListKind::Allow): array
    {
        $resolve = fn (?Audience $audience) => $kind === ListKind::Block ? $this->defaultBlockList($audience) : $this->defaultAllowList($audience);

        if ($scope === AccessScope::SiteWide) {
            return [$resolve(null)];
        }

        return array_map($resolve, $scope->audiences());
    }

    public function kindFor(WebsiteRequest $request): ListKind
    {
        return $request->isBlock() ? ListKind::Block : ListKind::Allow;
    }

    /** @return Collection<int, FilterPolicy> */
    public function policiesFor(AccessScope $scope): Collection
    {
        return FilterPolicy::query()->where('enabled', true)
            ->when($scope !== AccessScope::SiteWide, fn ($query) => $query->whereIn('audience', $scope->audiences()))
            ->ordered()->get();
    }

    /**
     * Place an approved request's domains into its target lists.
     *
     * @param  list<string>  $domains
     */
    public function grant(WebsiteRequest $request, array $domains, AccessScope $scope, ?User $actor): void
    {
        DB::transaction(function () use ($request, $domains, $scope, $actor): void {
            foreach ($this->targetListsFor($scope, $this->kindFor($request)) as $list) {
                foreach ($domains as $domain) {
                    $this->upsertEntry($list, $domain, [
                        'source' => EntrySource::Request,
                        'website_request_id' => $request->id,
                        'added_by_id' => $actor?->id,
                        'starts_at' => $request->accessFrom(),
                        'expires_at' => $request->accessUntil(),
                        'note' => "{$request->reference}: {$request->category}",
                    ]);
                }
            }
        });
    }

    /**
     * Remove a request's entries, handing any shared entry to another
     * still-granted request for the same domain and list.
     */
    public function withdraw(WebsiteRequest $request): int
    {
        return DB::transaction(function () use ($request): int {
            $removed = 0;
            $entries = PolicyEntry::query()->where('website_request_id', $request->id)->where('source', EntrySource::Request)->get();
            $others = $entries->isEmpty() ? collect() : WebsiteRequest::query()
                ->whereKeyNot($request->id)
                ->whereIn('status', RequestStatus::granted())
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->get();

            foreach ($entries as $entry) {
                $successor = $others->first(fn (WebsiteRequest $other) => in_array($entry->domain, $other->effectiveDomains(), true)
                    && collect($this->targetListsFor($other->effectiveScope(), $this->kindFor($other)))->contains('id', $entry->policy_list_id));

                if ($successor) {
                    $entry->update([
                        'website_request_id' => $successor->id,
                        'starts_at' => $successor->accessFrom(),
                        'expires_at' => $successor->accessUntil(),
                        'note' => "{$successor->reference}: {$successor->category}",
                    ]);

                    continue;
                }

                $entry->delete();
                $removed++;
            }

            return $removed;
        });
    }

    /**
     * Add or merge a domain into a list. A permanent entry is never narrowed
     * by a later temporary one; a temporary entry is widened to the later end.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function upsertEntry(PolicyList $list, string $domain, array $attributes): PolicyEntry
    {
        $existing = PolicyEntry::query()->where('policy_list_id', $list->id)->where('domain', $domain)->first();

        if (! $existing) {
            return PolicyEntry::query()->create(['policy_list_id' => $list->id, 'domain' => $domain] + $attributes);
        }

        if ($existing->expires_at === null && $existing->starts_at === null) {
            return $existing;
        }

        $expires = $attributes['expires_at'] ?? null;
        $starts = $attributes['starts_at'] ?? null;
        $existing->fill([
            'expires_at' => $expires === null ? null : max($existing->expires_at, Carbon::parse($expires)),
            'starts_at' => $starts === null || $existing->starts_at === null ? null : min($existing->starts_at, Carbon::parse($starts)),
        ] + array_intersect_key($attributes, array_flip(['source', 'website_request_id', 'note'])))->save();

        return $existing;
    }

    /**
     * @return array{
     *     allow: list<string>, block: list<string>, conflicts: list<string>, shadowed: list<array{block: string, allow: string}>,
     *     sources: array<string, list<array{list: string, list_id: int, kind: string, source: string, expires_at: ?string, request: ?string}>>
     * }
     */
    public function desired(FilterPolicy $policy, ?CarbonInterface $at = null): array
    {
        $lists = PolicyList::query()->appliesTo($policy)->get();
        $allow = [];
        $block = [];
        $sources = [];

        foreach ($lists as $list) {
            $specificity = match ($list->scope) {
                ListScope::Policy => 2,
                ListScope::Audience => 1,
                ListScope::Site => 0,
            };
            // Plain rows, not models: feed lists can hold tens of thousands of entries.
            $entries = PolicyEntry::query()->where('policy_list_id', $list->id)->effective($at)->toBase()
                ->get(['id', 'domain', 'source', 'website_request_id', 'expires_at', 'created_at']);
            $references = WebsiteRequest::query()->whereIn('id', $entries->pluck('website_request_id')->filter()->unique()->all())->pluck('reference', 'id');
            $feed = EntrySource::Feed->value;

            foreach ($entries as $entry) {
                $isFeed = $entry->source === $feed;
                // Feed entries rank below entries IT or a request added at the same level.
                $rank = [$specificity, $isFeed ? 0 : 1, $entry->created_at ? strtotime((string) $entry->created_at) : 0, $entry->id];
                if ($list->kind === ListKind::Allow) {
                    $allow[$entry->domain] = max($allow[$entry->domain] ?? [-1, 0, 0, 0], $rank);
                } else {
                    $block[$entry->domain] = max($block[$entry->domain] ?? [-1, 0, 0, 0], $rank);
                }
                if (! $isFeed) {
                    $sources[$entry->domain][] = [
                        'list' => $list->name, 'list_id' => $list->id, 'kind' => $list->kind->value,
                        'source' => $entry->source, 'expires_at' => $entry->expires_at ? Carbon::parse($entry->expires_at)->toIso8601String() : null,
                        'request' => $entry->website_request_id ? ($references[$entry->website_request_id] ?? null) : null,
                    ];
                }
            }
        }

        $exclusions = $policy->exclusions();
        foreach ($exclusions['allow'] as $domain) {
            unset($allow[$domain]);
        }
        foreach ($exclusions['block'] as $domain) {
            unset($block[$domain]);
        }

        // A hostname in both an allow and a block list: the more specific
        // list wins (one policy > an audience > site-wide); at the same level
        // the most recently added entry wins.
        $conflicts = [];
        $resolved = [];
        foreach (array_intersect_key($allow, $block) as $domain => $allowRank) {
            $conflicts[] = $domain;
            $blockWins = $block[$domain] > $allowRank;
            $resolved[$domain] = $blockWins ? 'block' : 'allow';
            if ($blockWins) {
                unset($allow[$domain]);
            } else {
                unset($block[$domain]);
            }
        }
        sort($conflicts);

        $rules = $this->rules();
        $allowList = $this->recommender->compact(array_keys($allow), $rules);
        $blockList = array_keys($block);
        sort($blockList);

        $shadowed = [];
        if ($allowList !== [] && $blockList !== []) {
            $blocked = array_flip($blockList);
            foreach ($allowList as $allowed) {
                $labels = explode('.', $allowed);
                for ($index = 1; $index < count($labels) - 1; $index++) {
                    $parent = implode('.', array_slice($labels, $index));
                    if (isset($blocked[$parent])) {
                        $shadowed[] = ['block' => $parent, 'allow' => $allowed];
                    }
                }
            }
        }

        return [
            'allow' => $allowList,
            'block' => $this->recommender->compact($blockList, $rules),
            'conflicts' => $conflicts,
            'resolved' => $resolved,
            'shadowed' => $shadowed,
            'sources' => $sources,
            'excluded' => $exclusions,
        ];
    }

    /**
     * Compare the desired state with the last pasted provider snapshot.
     *
     * @return array<string, mixed>
     */
    public function reconcile(FilterPolicy $policy): array
    {
        $desired = $this->desired($policy);
        $rules = $this->rules();
        $currentAllow = $policy->current_allowlist ?? [];
        $currentBlock = $policy->current_blocklist ?? [];
        $hasSnapshot = $policy->snapshot_at !== null;

        $allowSet = array_flip($currentAllow);
        $desiredAllowSet = array_flip($desired['allow']);
        $desiredBlockSet = array_flip($desired['block']);
        $currentBlockSet = array_flip($currentBlock);

        $allowAdd = array_values(array_filter($desired['allow'], fn (string $domain) => ! $this->coveredBy($allowSet, $domain, $rules)));
        $allowExtra = array_values(array_filter($currentAllow, fn (string $domain) => ! isset($desiredAllowSet[$domain])));
        $blockAdd = array_values(array_filter($desired['block'], fn (string $domain) => ! isset($currentBlockSet[$domain])));
        $blockExtra = array_values(array_filter($currentBlock, fn (string $domain) => ! isset($desiredBlockSet[$domain])));

        $written = $policy->managed();
        $managedBlocks = array_flip($written['block']) + ($blockExtra === [] ? [] : array_flip(PolicyEntry::query()->whereIn('domain', array_slice($blockExtra, 0, 5000))
            ->whereHas('policyList', fn ($query) => $query->where('kind', ListKind::Block))->pluck('domain')->all()));
        $blockRemove = array_values(array_filter($blockExtra, fn (string $domain) => isset($managedBlocks[$domain])
            || isset($desiredAllowSet[$domain]) || $this->coveredBy($desiredAllowSet, $domain, $rules)));

        $known = collect($written['allow'])->merge(PolicyEntry::query()->whereIn('domain', array_slice($allowExtra, 0, 5000))->pluck('domain'))
            ->merge(WebsiteRequest::query()->whereIn('status', [RequestStatus::Expired, RequestStatus::Revoked])->latest()->limit(2000)->get()->flatMap->effectiveDomains())
            ->unique()->all();

        return $desired + [
            'has_snapshot' => $hasSnapshot,
            'current_allow' => $currentAllow,
            'current_block' => $currentBlock,
            'allow_add' => $hasSnapshot ? $allowAdd : $desired['allow'],
            'allow_remove' => array_values(array_intersect($allowExtra, $known)),
            'allow_review' => array_values(array_diff($allowExtra, $known)),
            'block_add' => $hasSnapshot ? $blockAdd : $desired['block'],
            'block_remove' => $blockRemove,
            'block_review' => array_values(array_diff($blockExtra, $blockRemove)),
            'controller_blocks_allowed' => array_values(array_intersect($currentBlock, $desired['allow'])),
            'in_sync' => $hasSnapshot && $allowAdd === [] && $allowExtra === [] && $blockAdd === [] && $blockExtra === [],
        ];
    }

    /**
     * Whether a hostname is matched by any entry in a set (domain => true),
     * itself or, when entries cover subdomains, a parent domain.
     *
     * @param  array<string, mixed>  $set
     */
    public function coveredBy(array $set, string $host, ?DomainRules $rules = null): bool
    {
        if (isset($set[$host])) {
            return true;
        }
        if (! ($rules ?? $this->rules())->entriesIncludeSubdomains) {
            return false;
        }
        $labels = explode('.', $host);
        for ($index = 1; $index < count($labels) - 1; $index++) {
            if (isset($set[implode('.', array_slice($labels, $index))])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where a hostname is allowed or blocked, including parent-domain entries.
     *
     * @return Collection<int, PolicyEntry>
     */
    public function locate(string $domain): Collection
    {
        $labels = explode('.', $domain);
        $candidates = [];
        for ($index = 0; $index < count($labels) - 1; $index++) {
            $candidates[] = implode('.', array_slice($labels, $index));
        }

        return PolicyEntry::query()->whereIn('domain', $candidates)
            ->with(['policyList.filterPolicy', 'websiteRequest:id,reference', 'addedBy:id,name'])
            ->orderByRaw('length(domain) desc')->get();
    }

    /**
     * Hostnames already allowed for every policy in a scope (used to avoid
     * recommending entries that already exist).
     *
     * @return list<string>
     */
    public function alreadyAllowed(AccessScope $scope): array
    {
        $policies = $this->policiesFor($scope);
        if ($policies->isEmpty()) {
            return [];
        }

        $sets = $policies->map(fn (FilterPolicy $policy) => $this->desired($policy)['allow']);

        return array_values(array_intersect(...$sets->push($sets->first())->all()));
    }

    /**
     * Entries starting or ending within a window, so IT knows what changes
     * the next reconciliation will produce.
     *
     * @return array{starting: Collection<int, PolicyEntry>, expiring: Collection<int, PolicyEntry>}
     */
    public function upcoming(int $hours = 24): array
    {
        $until = now()->addHours($hours);

        ['lead' => $lead, 'lag' => $lag] = PolicyEntry::margins();

        return [
            'starting' => PolicyEntry::query()->with('policyList', 'websiteRequest:id,reference')->whereBetween('starts_at', [now()->addMinutes($lead), $until->copy()->addMinutes($lead)])->orderBy('starts_at')->get(),
            'expiring' => PolicyEntry::query()->with('policyList', 'websiteRequest:id,reference')->whereBetween('expires_at', [now()->subMinutes($lag), $until->copy()->subMinutes($lag)])->orderBy('expires_at')->get(),
        ];
    }

    /** The allow or block list that belongs to one policy only, created on first use. */
    public function ownList(FilterPolicy $policy, ListKind $kind, ?string $description = null): PolicyList
    {
        return PolicyList::query()->firstOrCreate(
            ['scope' => ListScope::Policy, 'filter_policy_id' => $policy->id, 'kind' => $kind],
            ['name' => "{$policy->name} ".($kind === ListKind::Allow ? 'allow' : 'block'), 'description' => $description ?? "Entries for {$policy->name} only.", 'is_default' => false],
        );
    }

    public function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'policy';
        $slug = $base;
        $suffix = 2;
        while (FilterPolicy::query()->where('slug', $slug)->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    private function ensureList(ListKind $kind, ListScope $scope, ?Audience $audience, string $name, string $description, bool $default = false): PolicyList
    {
        $list = PolicyList::query()->where('kind', $kind)->where('scope', $scope)
            ->when($audience, fn ($query) => $query->where('audience', $audience), fn ($query) => $query->whereNull('audience'))
            ->orderBy('id')->first();

        return $list ?? PolicyList::query()->create([
            'name' => $name, 'kind' => $kind, 'scope' => $scope, 'audience' => $audience,
            'description' => $description, 'is_default' => $default || $scope === ListScope::Site,
        ]);
    }
}
