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
                $hash = hash('sha256', json_encode([$chunks, $names, array_values($list->rule_zones ?? []), (bool) $list->block_incoming]));
                $groups = array_values($list->groups ?? []);

                if (! $force && $hash === $list->pushed_hash && count($groups) === $count) {
                    return ['ok' => true, 'skipped' => true, 'message' => "{$list->name}: unchanged since the last push, nothing sent."];
                }

                $rules = array_values($list->rules ?? []);
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

                    // Blocking rules: one per group and zone, created by this app.
                    $wanted = [];
                    if ($wantRules) {
                        $zones = collect($manager->firewallZones())->keyBy('id');
                        $external = $zones->first(fn (array $zone) => strtolower($zone['name']) === 'external');
                        foreach ($groups as $group) {
                            foreach ($list->rule_zones as $zoneId) {
                                $wanted[] = ['group' => $group['id'], 'zone' => $zoneId, 'incoming' => false, 'name' => "Block {$group['name']} (".($zones[$zoneId]['name'] ?? 'zone').')'];
                            }
                            if ($list->block_incoming && $external) {
                                $wanted[] = ['group' => $group['id'], 'zone' => $external['id'], 'incoming' => true, 'name' => "Block incoming {$group['name']}"];
                            }
                        }
                        foreach ($wanted as $rule) {
                            $exists = collect($rules)->first(fn (array $existing) => $existing['group'] === $rule['group'] && $existing['zone'] === $rule['zone'] && ($existing['incoming'] ?? false) === $rule['incoming']);
                            if ($exists) {
                                continue;
                            }
                            $internal = $zones->first(fn (array $zone) => strtolower($zone['name']) === 'internal');
                            $rules[] = $rule + ['id' => $rule['incoming']
                                ? $manager->createBlockRule($rule['name'], $rule['group'], $list->family, $rule['zone'], (string) ($internal['id'] ?? $rule['zone']), groupIsSource: true)
                                : $manager->createBlockRule($rule['name'], $rule['group'], $list->family, $rule['zone'], (string) ($external['id'] ?? $rule['zone']))];
                        }
                    }
                    // Rules for zones no longer chosen.
                    foreach ($rules as $index => $rule) {
                        $still = collect($wanted)->contains(fn (array $want) => $want['group'] === $rule['group'] && $want['zone'] === $rule['zone'] && $want['incoming'] === ($rule['incoming'] ?? false));
                        if (! $still) {
                            $manager->deleteFirewallRule($rule['id']);
                            unset($rules[$index]);
                        }
                    }
                    $rules = array_values($rules);

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
                    .($usedBy === [] ? ' No firewall rule uses these groups yet, so nothing is blocked until you create the blocking rules.' : ' '.count($rules).' blocking rule(s) managed here.');

                return $this->record($list, $total > count($entries) ? 'truncated' : 'ok', $message, $actor);
            });
        } catch (LockTimeoutException) {
            return ['ok' => false, 'message' => "Another push to {$list->name} is still running."];
        }
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
        $list->forceFill(['rules' => [], 'rule_zones' => null, 'pushed_hash' => null])->save();
    }

    /** @return array{ok: bool, message: string} */
    private function record(FirewallList $list, string $status, string $message, ?User $actor): array
    {
        $list->forceFill(['last_pushed_at' => now(), 'last_push_status' => $status, 'last_push_message' => $message])->save();
        $this->audit->log('firewall.list_pushed', $list, ['status' => $status, 'message' => $message, 'by' => $actor?->id]);

        return ['ok' => $status !== 'failed', 'message' => "{$list->name}: {$message}"];
    }
}
