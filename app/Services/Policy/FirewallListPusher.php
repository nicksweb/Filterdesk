<?php

namespace App\Services\Policy;

use App\Models\FirewallList;
use App\Models\User;
use App\Notifications\FilterSyncAlertNotification;
use App\Services\AuditLogger;
use App\Services\Network\FirewallRuleMode;
use App\Services\Network\ManagesIpGroups;
use App\Services\Network\NetworkProviderManager;
use App\Services\Network\ProviderRequestFailed;
use App\Services\Requests\RequestNotifier;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Keeps each firewall IP list's controller IP group in step. Unlike content
 * filters, the group belongs to this app: its contents are replaced with
 * the manual entries plus enabled feeds. Writes need Active mode.
 */
class FirewallListPusher
{
    public function __construct(
        private readonly NetworkProviderManager $providers,
        private readonly AuditLogger $audit,
    ) {}

    public function manager(): ?ManagesIpGroups
    {
        try {
            $provider = $this->providers->current();
        } catch (Throwable) {
            return null;
        }

        return $provider instanceof ManagesIpGroups ? $provider : null;
    }

    public function canWrite(): bool
    {
        return $this->manager() !== null && $this->providers->integration()?->mode === 'active';
    }

    /** UniFi rejects an IP group above about 20,000 addresses (30,000 returned HTTP 400). */
    public const GROUP_LIMIT = 20000;

    /** The most the setting allows: reported to work on recent UniFi, not yet confirmed here (filterdesk:test-ip-list-size). */
    public const MAX_GROUP_LIMIT = 100000;

    /** Whether Filterdesk may write firewall rules, or keeps IP lists only (Settings → Network provider). */
    public function managesRules(): bool
    {
        return FirewallRuleMode::allowsRules($this->providers->integration());
    }

    public function groupLimit(): int
    {
        return min(self::MAX_GROUP_LIMIT, max(100, (int) ($this->providers->integration()?->configuration['max_ip_group_entries'] ?? self::GROUP_LIMIT)));
    }

    /**
     * Bring the list's controller groups (and the blocking rules the app
     * created for them) in step. A list larger than one group is split into
     * "Name (1 of 3)" groups. Unchanged lists are skipped unless forced.
     *
     * @return array{ok: bool, message: string, skipped?: bool}
     */
    public function push(FirewallList $list, ?User $actor, bool $force = false): array
    {
        return app(PolicyPublisher::class)->within('firewall', $actor, function () use ($list, $actor, $force): array {
            $result = $this->pushNow($list, $actor, $force);
            app(PolicyPublisher::class)->recordFirewall($list, $result);

            return $result;
        });
    }

    /**
     * Why the list differs from what was last pushed, or null when it does not.
     */
    public function pendingReason(FirewallList $list): ?string
    {
        if (! $list->pushed_hash) {
            return 'Not pushed yet.';
        }
        ['hash' => $hash, 'count' => $count] = $this->desiredHash($list);

        return $hash !== $list->pushed_hash || count(array_filter($list->groups ?? [], fn (array $group) => empty($group['spare']))) !== $count ? 'Its addresses or blocking rules changed since the last push.' : null;
    }

    /**
     * Fingerprint of what a push would write: the chunks, their group names,
     * the chosen zones and the rule options.
     *
     * @return array{hash: string, count: int, chunks: list<list<string>>, names: list<string>, entries: list<string>, total: int}
     */
    private function desiredHash(FirewallList $list, ?array $zoneIds = null): array
    {
        ['entries' => $entries, 'total' => $total] = $list->desiredEntries();
        $chunks = array_chunk($entries, $this->groupLimit());
        $count = count($chunks);
        $names = array_map(fn (int $index) => $count === 1 ? $list->name : "{$list->name} (".($index + 1)." of {$count})", array_keys($chunks));
        $parts = [$chunks, $names, array_values($zoneIds ?? $list->rule_zones ?? []), (bool) $list->block_incoming];
        if ($list->rules_first) {
            $parts[] = 'first';
        }
        if ($list->isAllow()) {
            $parts[] = ['allow', (bool) $list->all_zones, array_values($list->source_networks ?? []), $list->placement ?? 'top'];
        }

        return ['hash' => hash('sha256', json_encode($parts)), 'count' => $count, 'chunks' => $chunks, 'names' => $names, 'entries' => $entries, 'total' => $total];
    }

    /** @return array{ok: bool, message: string, skipped?: bool} */
    private function pushNow(FirewallList $list, ?User $actor, bool $force): array
    {
        $manager = $this->manager();
        if (! $manager || ! $this->canWrite()) {
            return ['ok' => false, 'message' => 'Pushing is off. Set the network provider to Active under Settings → Network provider.'];
        }

        try {
            return Cache::lock('firewall-push:'.$list->id, 300)->block(30, function () use ($list, $manager, $actor, $force): array {
                // "Every zone" follows UniFi: a zone created since the last push gets its rule now.
                $zoneIds = $list->all_zones ? rescue(fn () => array_column($this->ruleZones($list, $manager->firewallZones()), 'id'), null, false) : null;
                ['hash' => $hash, 'count' => $count, 'chunks' => $chunks, 'names' => $names, 'entries' => $entries, 'total' => $total] = $this->desiredHash($list, $zoneIds);
                if ($entries === []) {
                    return $this->record($list, 'failed', 'There are no addresses to push yet. '.($list->isAllow() ? 'Add addresses or domains first.' : 'Refresh its feeds or add manual entries first.'), $actor);
                }
                // "IP lists only": the groups are kept up to date and every rule is left to IT, including
                // rules made here before (they stay recorded on the list, so the page can list them).
                $managesRules = $this->managesRules();
                $wantRules = $managesRules && ($list->all_zones || ($list->rule_zones !== null && $list->rule_zones !== []));
                $groups = array_values($list->groups ?? []);
                $rules = $managesRules ? array_values($list->rules ?? []) : [];
                $partsBefore = count(array_filter($groups, fn (array $group) => empty($group['spare'])));

                // Rules deleted in UniFi are recreated (a rule someone only disabled is left as it is).
                $missing = [];
                if ($rules !== []) {
                    try {
                        $live = collect($manager->firewallRules())->pluck('id')->all();
                        $missing = array_values(array_filter($rules, fn (array $rule) => ! in_array($rule['id'], $live, true)));
                        $rules = array_values(array_filter($rules, fn (array $rule) => in_array($rule['id'], $live, true)));
                    } catch (Throwable) {
                        // Checked again on the next push.
                    }
                }

                // Spare parts (kept, emptied, because a rule still uses them) don't count, and are reused first when the list grows again.
                $active = count(array_filter($groups, fn (array $group) => empty($group['spare'])));
                if (! $force && $missing === [] && $hash === $list->pushed_hash && $active === $count) {
                    return ['ok' => true, 'skipped' => true, 'message' => "{$list->name}: unchanged since the last push, nothing sent."];
                }
                try {
                    // Write or create one group per chunk.
                    foreach ($chunks as $index => $chunk) {
                        if (isset($groups[$index])) {
                            $manager->writeIpGroup($groups[$index]['id'], $names[$index], $list->family, $chunk);
                        } else {
                            $groups[$index] = ['id' => $manager->createIpGroup($names[$index], $list->family, $chunk)];
                        }
                        $groups[$index] = ['id' => $groups[$index]['id'], 'name' => $names[$index], 'count' => count($chunk)];
                    }

                    // The list shrank: remove our rules for the surplus groups, then the groups. A group a rule
                    // still uses is kept; with IP lists only it is emptied too, so it stops blocking old addresses.
                    $kept = [];
                    $keptGroups = [];
                    foreach (array_slice($groups, $count) as $surplus) {
                        foreach (array_filter($rules, fn (array $rule) => $rule['group'] === $surplus['id']) as $rule) {
                            $manager->deleteFirewallRule($rule['id']);
                        }
                        $rules = array_values(array_filter($rules, fn (array $rule) => $rule['group'] !== $surplus['id']));
                        if ($manager->firewallRulesUsing($surplus['id']) === []) {
                            $manager->deleteIpGroup($surplus['id']);
                        } else {
                            $emptied = ! $managesRules && rescue(fn () => $manager->writeIpGroup($surplus['id'], $surplus['name'], $list->family, []) ?? true, false, false);
                            $kept[] = $surplus['name'].($emptied ? ' (now empty)' : '');
                            $keptGroups[] = ['id' => $surplus['id'], 'name' => $surplus['name'], 'count' => $emptied ? 0 : ($surplus['count'] ?? 0), 'spare' => true];
                        }
                    }
                    $groups = [...array_slice($groups, 0, $count), ...($managesRules ? [] : $keptGroups)];
                    // New parts are not in any rule IT made: say so loudly.
                    $newParts = ! $managesRules && $count > $partsBefore ? array_slice($names, $partsBefore) : [];

                    // Blocking rules: one per group and zone pair, created by this app.
                    $wanted = $wantRules ? $this->wantedRules($list, $groups, $manager->firewallZones()) : [];
                    // A rule is kept when it is for the same group and zone (and, for an allow list, the same source networks).
                    $same = fn (array $a, array $b) => $a['group'] === $b['group'] && $a['zone'] === $b['zone'] && ($a['incoming'] ?? false) === ($b['incoming'] ?? false)
                        && array_values($a['networks'] ?? []) === array_values($b['networks'] ?? []);
                    foreach ($wanted as $rule) {
                        if (collect($rules)->contains(fn (array $existing) => $same($existing, $rule))) {
                            continue;
                        }
                        $rules[] = ['group' => $rule['group'], 'zone' => $rule['zone'], 'incoming' => $rule['incoming'], 'name' => $rule['name'], 'networks' => $rule['networks'] ?? [],
                            'id' => $manager->createBlockRule($rule['name'], $rule['group'], $list->family, $rule['source'], $rule['destination'], groupIsSource: $rule['incoming'],
                                action: $list->isAllow() ? 'ALLOW' : 'BLOCK', sourceNetworkIds: $rule['networks'] ?? [])];
                    }
                    // Rules for zones no longer chosen.
                    foreach ($rules as $index => $rule) {
                        if (! collect($wanted)->contains(fn (array $want) => $same($want, $rule))) {
                            $manager->deleteFirewallRule($rule['id']);
                            unset($rules[$index]);
                        }
                    }
                    $rules = $managesRules ? array_values($rules) : array_values($list->rules ?? []);

                    // Order at the top of each zone pair: every allow list's rules first (so allowed addresses
                    // get through), then block lists that asked to be first (above the Allow rules people made).
                    if ($managesRules && ($list->rules_first || $list->isAllow())) {
                        $list->forceFill(['rules' => $rules])->save();
                        foreach (collect($wanted)->groupBy(fn (array $want) => $want['source'].'>'.$want['destination']) as $pair) {
                            [$source, $destination] = [$pair->first()['source'], $pair->first()['destination']];
                            if ($list->isAllow() && $list->placement === 'above_blocks') {
                                // Just above the first block rule that matches one of the list's addresses, so general
                                // rules (and blocks for other sources) above it are still checked first.
                                $ours = collect($rules)->filter(fn (array $rule) => $rule['zone'] === $source)->pluck('id')->all();
                                $manager->placeRulesBefore($source, $destination, $ours, $this->firstMatchingBlock($list, $source) ?? '');
                            } else {
                                $manager->placeRulesFirst($source, $destination, $this->topOrder($source, $destination));
                            }
                        }
                    }

                    $usedBy = collect($groups)->flatMap(fn (array $group) => $manager->firewallRulesUsing($group['id']))->unique('name')->values()->all();
                } catch (Throwable $exception) {
                    // Keep what was created so far, so the next push carries on from there.
                    $list->forceFill(['groups' => $groups, 'rules' => $rules, 'provider_reference' => $groups[0]['id'] ?? $list->provider_reference])->save();
                    if (! $exception instanceof ProviderRequestFailed) {
                        report($exception);
                    }

                    return $this->record($list, 'failed', $exception instanceof ProviderRequestFailed ? $exception->getMessage() : 'The controller could not be reached. Nothing more was changed.', $actor);
                }

                $list->forceFill([
                    'groups' => $groups, 'rules' => $rules, 'pushed_hash' => $hash, 'referenced_by' => $usedBy, 'live_count' => count($entries),
                    'provider_reference' => $groups[0]['id'], 'provider_name' => $groups[0]['name'],
                ])->save();

                $message = number_format(count($entries)).' address(es) in '.($count === 1 ? $groups[0]['name'] : "{$count} groups of up to ".number_format($this->groupLimit())).'.'
                    .($total > count($entries) ? ' '.number_format($total - count($entries)).' more were left out by the limit of '.number_format($list->max_entries).'.' : '')
                    .($kept ? ' Kept '.implode(', ', $kept).' because a rule you made still uses it.' : '')
                    .($newParts ? ' NEW UniFi IP list: '.implode(', ', $newParts).'. Your firewall rules in UniFi don\'t use it yet, so its addresses aren\'t '.($list->isAllow() ? 'let through' : 'blocked').' until you add it to them.' : '')
                    .(! $managesRules ? ($usedBy === [] ? ' No firewall rule in UniFi uses these lists yet: add them to your own rules.' : ' Used by your rules: '.collect($usedBy)->pluck('name')->join(', ').'.')
                        : ($usedBy === [] ? ($list->isAllow() ? ' No firewall rule uses these groups yet, so nothing is let through until you choose the zones.' : ' No firewall rule uses these groups yet, so nothing is blocked until you create the blocking rules.')
                        : ' '.count($rules).($list->isAllow() ? ' allow rule(s), above the block rules for their zones.' : ' blocking rule(s) managed here.')))
                    .($missing ? ' Recreated '.count($missing).' rule(s) that had been deleted in UniFi.' : '');

                if ($newParts) {
                    $list->forceFill(['new_parts' => $newParts])->save();
                    app(RequestNotifier::class)->filterSyncAlert(new FilterSyncAlertNotification([[
                        'policy' => $list->name, 'slug' => '', 'status' => 'firewall', 'summary' => 'has a new UniFi IP list, '.implode(', ', $newParts).'. Add it to your firewall rules in UniFi: until then its addresses aren\'t '.($list->isAllow() ? 'let through.' : 'blocked.'),
                    ]]));
                }

                return $this->record($list, $total > count($entries) ? 'truncated' : 'ok', $message, $actor);
            });
        } catch (LockTimeoutException) {
            return ['ok' => false, 'message' => "Another push to {$list->name} is still running."];
        }
    }

    /**
     * The rules a list needs, for pushing and for showing beforehand. A UniFi
     * rule has one source zone, one destination zone and one IP group, so
     * there is one rule per group for each chosen zone (to External), and
     * for incoming blocks one per group from External to each chosen zone
     * and to the Gateway (VPN servers and other services on the gateway).
     *
     * @param  list<array{id: string, name?: string}>  $groups
     * @param  list<array{id: string, name: string}>  $zones
     * @param  list<string>|null  $chosen  zone ids; the list's own when null
     * @return list<array{group: string, zone: string, incoming: bool, name: string, source: string, destination: string, from: string, to: string}>
     *
     * @throws ProviderRequestFailed when UniFi has no External zone
     */
    public function wantedRules(FirewallList $list, array $groups, array $zones, ?array $chosen = null, ?bool $incoming = null): array
    {
        $chosen ??= $list->all_zones ? array_column($this->ruleZones($list, $zones), 'id') : ($list->rule_zones ?? []);
        $incoming ??= (bool) $list->block_incoming;
        $incoming = $incoming && ! $list->isAllow();
        $verb = $list->isAllow() ? 'Allow' : 'Block';
        $byId = collect($zones)->keyBy('id');
        $named = fn (string $name) => collect($zones)->first(fn (array $zone) => strtolower($zone['name']) === $name);
        $external = $named('external');
        $gateway = $named('gateway');
        if ($chosen === []) {
            return [];
        }
        if (! $external) {
            throw new ProviderRequestFailed('UniFi has no External zone, so the firewall rules cannot be created. Check that zone-based firewall is on (Settings → Security → Firewall).');
        }

        // An allow list limited to some networks: each zone's rule names those of its networks, and a zone with none gets no rule.
        $sources = $list->isAllow() ? array_values($list->source_networks ?? []) : [];
        $wanted = [];
        foreach ($groups as $group) {
            $label = $group['name'] ?? $list->name;
            foreach ($chosen as $zoneId) {
                $zone = $byId[$zoneId]['name'] ?? 'zone';
                $networks = $sources === [] ? [] : array_values(array_intersect($sources, array_column($byId[$zoneId]['members'] ?? [], 'id')));
                if ($sources !== [] && $networks === []) {
                    continue;
                }
                $wanted[] = ['group' => $group['id'], 'zone' => $zoneId, 'incoming' => false, 'name' => "{$verb} {$label} ({$zone})", 'networks' => $networks,
                    'source' => $zoneId, 'destination' => $external['id'], 'from' => $networks ? collect($byId[$zoneId]['members'] ?? [])->whereIn('id', $networks)->pluck('name')->join(', ')." ({$zone})" : $zone, 'to' => $external['name']];
            }
            if ($incoming) {
                foreach ([...$chosen, ...($gateway ? [$gateway['id']] : [])] as $zoneId) {
                    $zone = $byId[$zoneId]['name'] ?? 'zone';
                    $wanted[] = ['group' => $group['id'], 'zone' => $zoneId, 'incoming' => true, 'name' => "Block incoming {$label} ({$zone})",
                        'source' => $external['id'], 'destination' => $zoneId, 'from' => $external['name'], 'to' => $zone];
                }
            }
        }

        return $wanted;
    }

    /**
     * Rules people made that UniFi checks before this list's blocking rules
     * for the same zones and that allow traffic, so they could let listed
     * addresses through.
     *
     * @param  list<array{id: string, name: string, action: string, enabled: bool, index: int, source: string, destination: string, user: bool}>  $live
     * @return list<array{name: string, before: string}>
     */
    public function rulesAhead(FirewallList $list, array $live): array
    {
        $ours = collect($list->rules ?? [])->pluck('id')->all();
        // A block list is undone by an Allow rule checked first; an allow list by a Block rule checked first.
        $against = $list->isAllow() ? ['BLOCK', 'REJECT', 'DROP'] : ['ALLOW'];
        $ahead = [];
        foreach (collect($live)->whereIn('id', $ours) as $rule) {
            foreach ($live as $other) {
                if ($other['user'] && $other['enabled'] && in_array($other['action'], $against, true) && ! in_array($other['id'], $ours, true)
                    && $other['source'] === $rule['source'] && $other['destination'] === $rule['destination'] && $other['index'] < $rule['index']) {
                    $ahead[$other['id']] = ['name' => $other['name'], 'before' => $rule['name']];
                }
            }
        }

        return array_values($ahead);
    }

    /**
     * The zones "every zone" covers: all of them except External and Gateway
     * (traffic to the internet starts in the others, VPN and Hotspot included).
     *
     * @param  list<array{id: string, name: string}>  $zones
     * @return list<array{id: string, name: string}>
     */
    public function ruleZones(FirewallList $list, array $zones): array
    {
        return array_values(array_filter($zones, fn (array $zone) => ! in_array(strtolower($zone['name']), ['external', 'gateway'], true)));
    }

    /**
     * The rules this app keeps at the top of one zone pair, in order: allow
     * lists' rules, then block lists' rules that are to go first.
     *
     * @return list<string>
     */
    private function topOrder(string $source, string $destination): array
    {
        $lists = FirewallList::query()->where('enabled', true)->get()->filter(fn (FirewallList $list) => ($list->isAllow() && $list->placement !== 'above_blocks') || $list->rules_first)
            ->sortBy(fn (FirewallList $list) => [$list->isAllow() ? 0 : 1, $list->id]);
        $live = rescue(fn () => collect($this->manager()?->firewallRules() ?? [])->keyBy('id'), collect(), false);
        $inPair = fn (array $rule) => isset($live[$rule['id']])
            ? $live[$rule['id']]['source'] === $source && $live[$rule['id']]['destination'] === $destination
            // Not read back yet: an outgoing rule starts in its zone, an incoming one ends there.
            : ($rule['zone'] ?? null) === (($rule['incoming'] ?? false) ? $destination : $source);

        return $lists->flatMap(fn (FirewallList $list) => collect($list->rules ?? [])->filter($inPair)->pluck('id'))->values()->all();
    }

    /** The first block rule (in UniFi's order) that matches one of an allow list's addresses from a zone. */
    private function firstMatchingBlock(FirewallList $list, string $zoneId): ?string
    {
        $evaluator = app(FirewallEvaluator::class);
        $ours = collect($list->rules ?? [])->pluck('id')->all();

        return collect($list->desiredEntries()['entries'])->take(200)
            ->flatMap(fn (string $entry) => $evaluator->evaluate(explode('/', $entry)[0], $zoneId)['blocks'])
            ->reject(fn (array $block) => in_array($block['id'] ?? null, $ours, true))
            ->sortBy('index')->first()['id'] ?? null;
    }

    /**
     * Delete the blocking rules this app created for a list (leaving the
     * groups), for example before removing the list.
     */
    public function removeRules(FirewallList $list): void
    {
        $manager = $this->manager();
        foreach ($list->rules ?? [] as $rule) {
            rescue(fn () => $manager?->deleteFirewallRule($rule['id']), null, false);
        }
        $list->forceFill(['rules' => [], 'rule_zones' => null, 'rules_first' => false, 'all_zones' => false, 'pushed_hash' => null])->save();
    }

    /** @return array{ok: bool, message: string} */
    private function record(FirewallList $list, string $status, string $message, ?User $actor): array
    {
        $list->forceFill(['last_pushed_at' => now(), 'last_push_status' => $status, 'last_push_message' => $message])->save();
        $this->audit->log('firewall.list_pushed', $list, ['status' => $status, 'message' => $message, 'by' => $actor?->id]);

        return ['ok' => $status !== 'failed', 'message' => "{$list->name}: {$message}"];
    }
}
