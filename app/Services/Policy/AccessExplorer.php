<?php

namespace App\Services\Policy;

use App\Enums\ListKind;
use App\Models\FilterPolicy;
use App\Models\FirewallList;
use App\Models\NetworkType;
use App\Models\PolicyEntry;
use App\Models\WebsiteCheck;
use App\Services\Domains\DomainCategorizer;
use App\Support\Schedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * Answers "can this network type reach this site at this time, and why":
 * for each content filter of the type it traces the steps that decide it
 * (the filter, the site's category, every list entry with its schedule,
 * exam mode and proposals, the desired outcome and what the controller has
 * now), adds firewall IP lists and the latest probe result as evidence, and
 * names the step that decides the outcome.
 */
class AccessExplorer
{
    public function __construct(
        private readonly PolicyEngine $engine,
        private readonly ControllerSync $sync,
        private readonly DomainCategorizer $categorizer,
    ) {}

    /**
     * @return array{domain: string, type: NetworkType, at: CarbonInterface, verdict: string, reason: string, policies: list<array<string, mixed>>, categories: ?array, firewall: list<string>, evidence: ?array, pending: bool}
     */
    public function explain(string $domain, NetworkType $type, ?CarbonInterface $at = null): array
    {
        $domain = strtolower(trim($domain));
        $at ??= now();
        $categories = $this->categorizer->enabled() ? $this->category($domain) : null;
        $state = rescue(fn () => $this->sync->state(), ['filters' => []], false);

        $policies = FilterPolicy::query()->where('enabled', true)->whereNull('layer_of_id')->where('audience', $type->key)->ordered()->get()
            ->map(fn (FilterPolicy $policy) => $this->forPolicy($domain, $policy, $at, $categories, $state))->values()->all();

        $evidence = $this->evidence($domain, $type);
        $firewall = $this->firewall($domain, $evidence['addresses'] ?? []);

        $blocked = collect($policies)->firstWhere('verdict', 'blocked');
        [$verdict, $reason] = match (true) {
            $policies === [] => ['unknown', "No content filter policy is set up for {$type->name}, so this app does not filter it."],
            $firewall !== [] => ['blocked', 'Its address is in the firewall IP list '.implode(', ', $firewall).'.'],
            $blocked !== null => ['blocked', $blocked['reason']],
            default => ['allowed', collect($policies)->first()['reason']],
        };

        return [
            'domain' => $domain, 'type' => $type, 'at' => $at, 'verdict' => $verdict, 'reason' => $reason, 'policies' => $policies,
            'categories' => $categories, 'firewall' => $firewall, 'evidence' => $evidence,
            'pending' => collect($policies)->contains('pending', true),
        ];
    }

    /**
     * A short answer for requesters, without list names or internal detail.
     *
     * @return array{verdict: string, reason: string}
     */
    public function plain(array $explained): array
    {
        $reason = match (true) {
            $explained['verdict'] === 'unknown' => 'This network is not filtered by this app.',
            $explained['firewall'] !== [] => 'The site is on a security blocklist.',
            $explained['verdict'] === 'blocked' && collect($explained['policies'])->contains('deciding', 'category') => 'The school blocks its category ('.collect($explained['policies'])->firstWhere('deciding', 'category')['category'].').',
            $explained['verdict'] === 'blocked' => 'The school has blocked this site.',
            default => 'Nothing blocks it.',
        };

        return ['verdict' => $explained['verdict'], 'reason' => $reason.($explained['pending'] ? ' A change to this is waiting to be published.' : '')];
    }

    /** @return array<string, mixed> */
    private function forPolicy(string $domain, FilterPolicy $policy, CarbonInterface $at, ?array $categories, array $state): array
    {
        $filter = $this->sync->filterFor($policy, $state);
        $layer = $policy->activeLayer();
        $layerFilter = $layer ? $this->sync->filterFor($layer, $state) : null;
        $steps = [];

        $steps[] = $filter
            ? ['key' => 'filter', 'outcome' => 'info', 'label' => 'Content filter', 'detail' => "{$filter['name']} in the controller".($layerFilter ? ", with the layer {$layerFilter['name']}" : '').'.']
            : ['key' => 'filter', 'outcome' => 'info', 'label' => 'Content filter', 'detail' => 'Not linked to a controller filter: the outcome below is what the policy wants, not what the network does.'];

        // The site's category, and whether this filter blocks it.
        $blockedCategory = null;
        if ($categories && $categories['ok']) {
            $names = implode(', ', $categories['categories']) ?: 'not categorised';
            $filterCategories = array_merge((array) ($filter['categories'] ?? []), (array) ($layerFilter['categories'] ?? []));
            $blockedCategory = collect($categories['unifi'])->first(fn (array $match) => in_array($match['id'], $filterCategories, true));
            $steps[] = ['key' => 'category', 'outcome' => $blockedCategory ? 'block' : 'neutral', 'label' => 'Category',
                'detail' => $blockedCategory ? "{$names}: the filter blocks {$blockedCategory['label']}." : "{$names}: no blocked category matches."];
        } else {
            $steps[] = ['key' => 'category', 'outcome' => 'neutral', 'label' => 'Category', 'detail' => $categories === null ? 'Website categories are off (Settings → Website categories), so a category block cannot be ruled out.' : ($categories['message'] ?? 'Not categorised.')];
        }

        // Every entry for the site (or a parent domain) in a list that reaches this policy.
        $entries = $this->engine->locate($domain)->filter(fn (PolicyEntry $entry) => $entry->policyList->appliesTo($policy) || ($layer && $entry->policyList->appliesTo($layer)));
        foreach ($entries as $entry) {
            $list = $entry->policyList;
            $state = match (true) {
                $entry->domain !== $domain && ! $this->engine->rules()->entriesIncludeSubdomains => 'this controller does not apply an entry to subdomains, so it does not cover '.$domain,
                $entry->pending_action === 'add' => 'proposed, waiting for a second IT officer (not live)',
                $list->purpose === 'exam' && ! NetworkType::examActiveFor($list->audience, $at) => 'exam mode is off, so it does not apply now',
                ! $entry->isEffective($at) => $entry->starts_at && $entry->effectiveFrom()?->gt($at) ? 'starts '.$entry->starts_at->local()->format('j M g:i a') : 'ended',
                $entry->schedule && ! (Schedule::fromArray($entry->schedule)?->isActive($at) ?? true) => 'its recurring schedule is off at this time',
                default => 'applies',
            };
            $steps[] = ['key' => 'entry', 'outcome' => $state === 'applies' ? $list->kind->value : 'neutral', 'label' => $list->kind === ListKind::Allow ? 'Allow entry' : 'Block entry',
                'detail' => "{$entry->domain} in {$list->name} (".$entry->source->value.($entry->websiteRequest ? ", {$entry->websiteRequest->reference}" : '').'): '.$state.'.'];
        }

        // What the policy wants, after precedence, layers and exclusions.
        $rules = $this->engine->rules();
        $desired = $this->engine->desired($policy, $at);
        $layerDesired = $layer ? $this->engine->desired($layer, $at) : null;
        $set = fn (array $domains) => array_flip(array_map('strtolower', $domains));
        $allowed = $this->engine->coveredBy($set($desired['allow']), $domain, $rules);
        $listBlocked = $this->engine->coveredBy($set($desired['block']), $domain, $rules) || ($layerDesired && $this->engine->coveredBy($set($layerDesired['block']), $domain, $rules));

        [$verdict, $deciding, $reason] = match (true) {
            $allowed => ['allowed', 'allow', "{$policy->name}: an allow entry lets it through".($blockedCategory ? " even though the category {$blockedCategory['label']} is blocked" : '').'.'],
            $listBlocked => ['blocked', 'block', "{$policy->name}: a block entry blocks it."],
            $blockedCategory !== null => ['blocked', 'category', "{$policy->name}: its category {$blockedCategory['label']} is blocked."],
            default => ['allowed', 'none', "{$policy->name}: nothing blocks it."],
        };

        // What the controller has right now, judged the same way.
        $live = null;
        if ($filter) {
            $liveAllowed = $this->engine->coveredBy($set((array) $filter['allow']), $domain, $rules);
            $liveBlocked = $this->engine->coveredBy($set((array) $filter['block']), $domain, $rules)
                || ($layerFilter && $this->engine->coveredBy($set((array) $layerFilter['block']), $domain, $rules));
            $live = $liveAllowed ? 'allowed' : ($liveBlocked || $blockedCategory ? 'blocked' : 'allowed');
        }
        $pending = $live !== null && $live !== $verdict;
        $steps[] = ['key' => 'outcome', 'outcome' => $verdict === 'allowed' ? 'allow' : 'block', 'label' => 'Outcome', 'detail' => $reason
            .($live === null ? '' : ($pending ? " The controller still has it {$live}: publish the waiting changes (Changes)." : ' The controller matches.'))];

        return ['policy' => $policy, 'filter' => $filter['name'] ?? null, 'steps' => $steps, 'verdict' => $verdict, 'deciding' => $deciding,
            'reason' => $reason, 'live' => $live, 'pending' => $pending, 'category' => $blockedCategory['label'] ?? null];
    }

    /** @return array{ok: bool, categories: list<string>, risks: list<string>, unifi: list<array{id: string, label: string, blocked_by: list<string>}>, message?: string}|null */
    private function category(string $domain): ?array
    {
        try {
            return $this->categorizer->lookup($domain);
        } catch (Throwable) {
            return ['ok' => false, 'categories' => [], 'risks' => [], 'unifi' => [], 'message' => 'The category service could not be reached.'];
        }
    }

    /**
     * The latest website check of the site from this type's probe, within a week.
     *
     * @return array{status: string, when: CarbonInterface, profile: string, addresses: list<string>}|null
     */
    private function evidence(string $domain, NetworkType $type): ?array
    {
        $checks = WebsiteCheck::query()->where('hostname', $domain)->where('created_at', '>=', now()->subWeek())->latest()->limit(10)->get();
        foreach ($checks as $check) {
            foreach ((array) $check->results as $result) {
                if (($result['audience'] ?? null) === $type->key && isset($result['status'])) {
                    return ['status' => (string) $result['status'], 'when' => CarbonImmutable::parse($check->created_at), 'profile' => (string) ($result['profile'] ?? $type->name),
                        'addresses' => array_values(array_filter((array) ($result['addresses'] ?? []), 'is_string'))];
                }
            }
        }

        return null;
    }

    /**
     * Firewall IP lists with blocking rules that hold one of the addresses a
     * probe resolved for the site.
     *
     * @param  list<string>  $addresses
     * @return list<string>
     */
    private function firewall(string $domain, array $addresses): array
    {
        // Only addresses a probe saw: a DNS lookup here would be slow and would ask from the server's network, not the school's.
        if ($addresses === []) {
            return [];
        }

        return FirewallList::query()->where('enabled', true)->get()
            ->filter(fn (FirewallList $list) => ! empty($list->rules) || ! empty($list->referenced_by))
            ->filter(fn (FirewallList $list) => collect($list->desiredEntries()['entries'])->contains(fn (string $entry) => collect($addresses)->contains(fn (string $address) => IpUtils::checkIp($address, $entry))))
            ->pluck('name')->values()->all();
    }

    /** @return Collection<int, NetworkType> */
    public function types(): Collection
    {
        return NetworkType::query()->ordered()->get();
    }
}
