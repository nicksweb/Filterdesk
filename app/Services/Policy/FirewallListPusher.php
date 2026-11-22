<?php

namespace App\Services\Policy;

use App\Models\FirewallList;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Network\ManagesIpGroups;
use App\Services\Network\NetworkProviderManager;
use App\Services\Network\ProviderRequestFailed;
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

    public function groupLimit(): int
    {
        return max(100, (int) ($this->providers->integration()?->configuration['max_ip_group_entries'] ?? self::GROUP_LIMIT));
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
        $manager = $this->manager();
        if (! $manager || ! $this->canWrite()) {
            return ['ok' => false, 'message' => 'Pushing is off. Set the network provider to Active under Settings → Network provider.'];
        }

        try {
            return Cache::lock('firewall-push:'.$list->id, 300)->block(30, function () use ($list, $manager, $actor, $force): array {
                ['entries' => $entries, 'total' => $total] = $list->desiredEntries();
                if ($entries === []) {
                    return $this->record($list, 'failed', 'There are no addresses to push yet. Refresh its feeds or add manual entries first.', $actor);
                }
                $chunks = array_chunk($entries, $this->groupLimit());
                $count = count($chunks);
                $names = array_map(fn (int $index) => $count === 1 ? $list->name : "{$list->name} (".($index + 1)." of {$count})", array_keys($chunks));
                $wantRules = $list->rule_zones !== null && $list->rule_zones !== [];
                $parts = [$chunks, $names, array_values($list->rule_zones ?? []), (bool) $list->block_incoming];
                if ($list->rules_first) {
                    $parts[] = 'first';
                }
                $hash = hash('sha256', json_encode($parts));
                $groups = array_values($list->groups ?? []);
                $rules = array_values($list->rules ?? []);

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

                if (! $force && $missing === [] && $hash === $list->pushed_hash && count($groups) === $count) {
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

                    // The list shrank: remove our rules for the surplus groups, then the groups.
                    $kept = [];
                    foreach (array_slice($groups, $count) as $surplus) {
                        foreach (array_filter($rules, fn (array $rule) => $rule['group'] === $surplus['id']) as $rule) {
                            $manager->deleteFirewallRule($rule['id']);
                        }
                        $rules = array_values(array_filter($rules, fn (array $rule) => $rule['group'] !== $surplus['id']));
                        if ($manager->firewallRulesUsing($surplus['id']) === []) {
                            $manager->deleteIpGroup($surplus['id']);
                        } else {
                            $kept[] = $surplus['name'];
                        }
                    }
                    $groups = array_slice($groups, 0, $count);

                    // Blocking rules: one per group and zone pair, created by this app.
                    $wanted = $wantRules ? $this->wantedRules($list, $groups, $manager->firewallZones()) : [];
                    $same = fn (array $a, array $b) => $a['group'] === $b['group'] && $a['zone'] === $b['zone'] && ($a['incoming'] ?? false) === ($b['incoming'] ?? false);
                    foreach ($wanted as $rule) {
                        if (collect($rules)->contains(fn (array $existing) => $same($existing, $rule))) {
                            continue;
                        }
                        $rules[] = ['group' => $rule['group'], 'zone' => $rule['zone'], 'incoming' => $rule['incoming'], 'name' => $rule['name'],
                            'id' => $manager->createBlockRule($rule['name'], $rule['group'], $list->family, $rule['source'], $rule['destination'], groupIsSource: $rule['incoming'])];
                    }
                    // Rules for zones no longer chosen.
                    foreach ($rules as $index => $rule) {
                        if (! collect($wanted)->contains(fn (array $want) => $same($want, $rule))) {
                            $manager->deleteFirewallRule($rule['id']);
                            unset($rules[$index]);
                        }
                    }
                    $rules = array_values($rules);

                    // Above the rules people made for the same zones, so their Allow rules cannot let these addresses through.
                    if ($list->rules_first) {
                        foreach (collect($wanted)->groupBy(fn (array $want) => $want['source'].'>'.$want['destination']) as $pair) {
                            $ids = $pair->map(fn (array $want) => collect($rules)->first(fn (array $rule) => $same($rule, $want))['id'] ?? null)->filter()->values()->all();
                            $manager->placeRulesFirst($pair->first()['source'], $pair->first()['destination'], $ids);
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
                    .($usedBy === [] ? ' No firewall rule uses these groups yet, so nothing is blocked until you create the blocking rules.' : ' '.count($rules).' blocking rule(s) managed here.')
                    .($missing ? ' Recreated '.count($missing).' rule(s) that had been deleted in UniFi.' : '');

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
        $chosen ??= $list->rule_zones ?? [];
        $incoming ??= (bool) $list->block_incoming;
        $byId = collect($zones)->keyBy('id');
        $named = fn (string $name) => collect($zones)->first(fn (array $zone) => strtolower($zone['name']) === $name);
        $external = $named('external');
        $gateway = $named('gateway');
        if ($chosen === []) {
            return [];
        }
        if (! $external) {
            throw new ProviderRequestFailed('UniFi has no External zone, so the blocking rules cannot be created. Check that zone-based firewall is on (Settings → Security → Firewall).');
        }

        $wanted = [];
        foreach ($groups as $group) {
            $label = $group['name'] ?? $list->name;
            foreach ($chosen as $zoneId) {
                $zone = $byId[$zoneId]['name'] ?? 'zone';
                $wanted[] = ['group' => $group['id'], 'zone' => $zoneId, 'incoming' => false, 'name' => "Block {$label} ({$zone})",
                    'source' => $zoneId, 'destination' => $external['id'], 'from' => $zone, 'to' => $external['name']];
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
        $ahead = [];
        foreach (collect($live)->whereIn('id', $ours) as $rule) {
            foreach ($live as $other) {
                if ($other['user'] && $other['enabled'] && $other['action'] === 'ALLOW' && ! in_array($other['id'], $ours, true)
                    && $other['source'] === $rule['source'] && $other['destination'] === $rule['destination'] && $other['index'] < $rule['index']) {
                    $ahead[$other['id']] = ['name' => $other['name'], 'before' => $rule['name']];
                }
            }
        }

        return array_values($ahead);
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
        $list->forceFill(['rules' => [], 'rule_zones' => null, 'rules_first' => false, 'pushed_hash' => null])->save();
    }

    /** @return array{ok: bool, message: string} */
    private function record(FirewallList $list, string $status, string $message, ?User $actor): array
    {
        $list->forceFill(['last_pushed_at' => now(), 'last_push_status' => $status, 'last_push_message' => $message])->save();
        $this->audit->log('firewall.list_pushed', $list, ['status' => $status, 'message' => $message, 'by' => $actor?->id]);

        return ['ok' => $status !== 'failed', 'message' => "{$list->name}: {$message}"];
    }
}
