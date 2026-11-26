<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RunManualPush;
use App\Models\FirewallList;
use App\Services\AuditLogger;
use App\Services\Policy\ControllerPusher;
use App\Services\Policy\FirewallAllowList;
use App\Services\Policy\FirewallEvaluator;
use App\Services\Policy\FirewallListPusher;
use App\Services\SettingsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Firewall allow lists: addresses, subnets and domains let through UniFi's
 * firewall by Allow rules this app keeps above the block rules for the
 * chosen zones, and optionally served at a secret URL as plain text.
 */
class FirewallAllowController extends Controller
{
    public function index(FirewallListPusher $pusher, FirewallEvaluator $firewall): View
    {
        $lists = FirewallList::query()->where('kind', 'allow')->orderBy('name')->get();
        $zones = $firewall->zones();
        $live = rescue(fn () => $pusher->manager()?->firewallRules() ?? [], [], false);
        $groups = rescue(fn () => $pusher->manager()?->ipGroups() ?? [], [], false);

        return view('admin.policy.firewall-allow', [
            'lists' => $lists,
            'zones' => $zones,
            'groups' => collect($groups),
            'error' => $firewall->error(),
            'canWrite' => $pusher->canWrite(),
            'unused' => $firewall->unusedGroups(),
            'firewallStatus' => $lists->mapWithKeys(fn (FirewallList $list) => [$list->id => Cache::get(RunManualPush::statusKey('firewall', $list->id))])->filter()->all(),
            'plans' => $lists->mapWithKeys(fn (FirewallList $list) => [$list->id => $this->plan($list, $pusher, $firewall, $zones, $live)])->all(),
        ]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:120'], 'family' => ['required', 'in:ipv4,ipv6']]);
        $list = FirewallList::query()->create($validated + ['kind' => 'allow', 'all_zones' => true, 'max_entries' => 2000]);
        $audit->log('firewall.allow_list_created', $list);

        return back()->with('success', "{$list->name} created. Add addresses or domains, then create its IP group in UniFi.");
    }

    public function add(Request $request, FirewallList $list, FirewallAllowList $allow, SettingsRepository $settings): RedirectResponse
    {
        abort_unless($list->isAllow(), 404);
        $validated = $request->validate([
            'values' => ['required', 'string', 'max:4000'],
            'note' => ['nullable', 'string', 'max:300'],
            'reason' => ['nullable', 'string', 'max:300'],
            'expires_at' => ['nullable', 'date'],
        ]);
        $expires = $settings->parseLocal($validated['expires_at'] ?? null);
        if ($expires?->isPast()) {
            return back()->withInput()->with('error', 'The expiry must be in the future.');
        }
        $values = preg_split('/[\s,]+/', $validated['values']) ?: [];
        $result = $allow->add($list, $values, $request->user(), $validated['note'] ?? null, null, $expires?->toIso8601String(), $validated['reason'] ?? null);

        $response = back();
        if ($result['refused']) {
            $response = $response->withInput()->with('error', 'Not added: '.implode(' ', $result['refused']));
        }

        return $result['added'] ? $this->pushAfter($response->with('success', 'Added to '.$list->name.': '.implode(', ', $result['added']).'.'.($result['warnings'] ? ' '.implode(' ', $result['warnings']) : '')), $list, $request) : $response;
    }

    public function remove(Request $request, FirewallList $list, FirewallAllowList $allow): RedirectResponse
    {
        abort_unless($list->isAllow(), 404);
        $validated = $request->validate(['entries' => ['required', 'array', 'max:500'], 'entries.*' => ['string', 'max:64']]);
        $removed = $allow->remove($list, $validated['entries'], $request->user());

        return $this->pushAfter(back()->with('success', "{$removed} entr".($removed === 1 ? 'y' : 'ies')." removed from {$list->name}."), $list, $request);
    }

    /** Which zones the Allow rules cover: every zone (following UniFi as zones are added) or the ticked ones. */
    public function zones(Request $request, FirewallList $list, FirewallListPusher $pusher, AuditLogger $audit): RedirectResponse
    {
        abort_unless($list->isAllow(), 404);
        $validated = $request->validate([
            'scope' => ['required', 'in:all,chosen,none'], 'zones' => ['nullable', 'array', 'max:20'], 'zones.*' => ['string', 'max:64'],
            'source' => ['nullable', 'in:zone,networks'], 'networks' => ['nullable', 'array', 'max:100'], 'networks.*' => ['string', 'max:64'],
            'placement' => ['nullable', 'in:top,above_blocks'],
        ]);
        $known = collect(rescue(fn () => $pusher->manager()?->firewallZones() ?? [], [], false));
        $zones = array_values(array_intersect($validated['zones'] ?? [], $known->pluck('id')->all()));
        $networks = ($validated['source'] ?? 'zone') === 'networks'
            ? array_values(array_intersect($validated['networks'] ?? [], $known->flatMap(fn (array $zone) => array_column($zone['members'] ?? [], 'id'))->all()))
            : [];
        $list->forceFill([
            'all_zones' => $validated['scope'] === 'all',
            'rule_zones' => $validated['scope'] === 'chosen' && $zones ? $zones : null,
            'source_networks' => $networks ?: null,
            'placement' => $validated['placement'] ?? 'top',
        ])->save();
        $audit->log('firewall.allow_zones_chosen', $list, ['scope' => $validated['scope'], 'zones' => $zones, 'networks' => $networks, 'placement' => $list->placement]);
        if (! $pusher->canWrite() || ! $list->provider_reference) {
            return back()->with('success', 'Saved. The rules are created when the list is pushed.');
        }
        $result = RunManualPush::start('firewall', $list->id, $request->user(), force: true);

        return RunManualPush::flash(back(), $result, "Updating the allow rules for {$list->name} in UniFi. It runs in the background; refresh in a minute.");
    }

    /** Take over an IP list that already exists in UniFi: link it and bring its addresses in as entries. */
    public function adopt(Request $request, FirewallList $list, FirewallListPusher $pusher, FirewallAllowList $allow, AuditLogger $audit): RedirectResponse
    {
        abort_unless($list->isAllow(), 404);
        $validated = $request->validate(['group' => ['required', 'string', 'max:190']]);
        $group = collect(rescue(fn () => $pusher->manager()?->ipGroups() ?? [], [], false))->firstWhere('id', $validated['group']);
        if (! $group || $group['family'] !== $list->family) {
            return back()->with('error', 'That UniFi IP list was not found, or holds the other address family.');
        }
        $result = $allow->add($list, $group['items'] ?? [], $request->user(), "Adopted from the UniFi list {$group['name']}", reason: 'Already allowed in UniFi');
        $list->forceFill(['provider_reference' => $group['id'], 'provider_name' => $group['name'], 'groups' => [['id' => $group['id'], 'name' => $group['name'], 'count' => (int) $group['count']]], 'pushed_hash' => null])->save();
        $audit->log('firewall.allow_list_adopted', $list, ['group' => $group['name'], 'entries' => count($result['added'])]);

        return back()->with('success', "{$list->name} now manages the UniFi list {$group['name']}: ".count($result['added']).' entr'.(count($result['added']) === 1 ? 'y' : 'ies').' brought in.'
            .($result['refused'] ? ' Left out: '.implode(' ', $result['refused']) : '').' Choose its zones and push to create the Allow rules.');
    }

    public function push(Request $request, FirewallList $list, FirewallListPusher $pusher): RedirectResponse
    {
        abort_unless($list->isAllow(), 404);
        if (! $pusher->canWrite()) {
            return back()->with('error', 'Pushing is off. Set the network provider to Active under Settings → Network provider.');
        }
        $result = RunManualPush::start('firewall', $list->id, $request->user(), force: true);

        return RunManualPush::flash(back(), $result, "Pushing {$list->name} to UniFi. It runs in the background; refresh in a minute.");
    }

    /** Run the nightly check now: moved, no longer resolving, no longer needed. */
    public function verify(FirewallList $list, FirewallAllowList $allow): RedirectResponse
    {
        abort_unless($list->isAllow(), 404);
        $flags = $allow->verify($list);

        return back()->with('success', $flags ? 'Checked. New to look at: '.implode(' ', $flags) : 'Checked: nothing new to look at.');
    }

    /** Turn the plain-text URL on (a new secret each time) or off. */
    public function feed(Request $request, FirewallList $list, AuditLogger $audit): RedirectResponse
    {
        abort_unless($list->isAllow(), 404);
        $on = $request->boolean('on');
        $list->forceFill(['feed_token' => $on ? Str::random(40) : null])->save();
        $audit->log($on ? 'firewall.allow_feed_on' : 'firewall.allow_feed_off', $list);

        return back()->with('success', $on ? 'A new URL was made. Any old URL stops working.' : 'The URL is off.');
    }

    public function destroy(FirewallList $list, FirewallListPusher $pusher, AuditLogger $audit): RedirectResponse
    {
        abort_unless($list->isAllow(), 404);
        if ($pusher->canWrite()) {
            $pusher->removeRules($list);
        }
        $audit->log('firewall.list_deleted', $list, ['name' => $list->name]);
        $list->delete();

        return back()->with('success', 'Removed, along with the allow rules this app created. The IP group was left in UniFi.');
    }

    /**
     * The rules this list keeps in UniFi, as UniFi has them: zones, what
     * they match, whether they're on, and where they sit in their zone's
     * order (with the rules just before and after).
     *
     * @param  list<array<string, mixed>>  $live
     * @param  list<array<string, mixed>>  $zones
     * @return list<array<string, mixed>>
     */
    public static function ruleDetails(FirewallList $list, array $live, array $zones): array
    {
        $names = collect($zones)->pluck('name', 'id');
        $members = collect($zones)->flatMap(fn (array $zone) => $zone['members'] ?? [])->pluck('name', 'id');

        return collect($list->rules ?? [])->map(function (array $rule) use ($live, $names, $members): array {
            $found = collect($live)->firstWhere('id', $rule['id']);
            $pair = $found ? collect($live)->filter(fn (array $other) => $other['source'] === $found['source'] && $other['destination'] === $found['destination'])->sortBy('index')->values() : collect();
            $position = $found ? $pair->search(fn (array $other) => $other['id'] === $rule['id']) : false;

            return [
                'name' => $rule['name'], 'found' => $found !== null, 'enabled' => (bool) ($found['enabled'] ?? false), 'action' => $found['action'] ?? null,
                'from' => $found ? ($names[$found['source']] ?? 'zone') : null, 'to' => $found ? ($names[$found['destination']] ?? 'zone') : null,
                'sources' => collect($rule['networks'] ?? [])->map(fn (string $id) => $members[$id] ?? $id)->all(),
                'position' => $position === false ? null : $position + 1, 'of' => $pair->count(),
                'before' => $position !== false && $position > 0 ? $pair[$position - 1]['name'] : null,
                'after' => $position !== false ? ($pair[$position + 1]['name'] ?? null) : null,
            ];
        })->all();
    }

    /** The list as plain text, one address or subnet per line, for a firewall that loads IP lists from a URL. */
    public function plain(string $token): Response
    {
        $list = FirewallList::query()->where('kind', 'allow')->where('enabled', true)->whereNotNull('feed_token')->get()
            ->first(fn (FirewallList $list) => hash_equals((string) $list->feed_token, $token));
        abort_unless($list !== null, 404);

        return response(implode("\n", $list->desiredEntries()['entries'])."\n", 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    private function pushAfter(RedirectResponse $response, FirewallList $list, Request $request): RedirectResponse
    {
        // With automatic pushing on, the change goes to UniFi straight away; otherwise it waits for Push.
        if ($list->provider_reference && app(FirewallListPusher::class)->canWrite() && app(ControllerPusher::class)->automatic()) {
            RunManualPush::start('firewall', $list->id, $request->user());
        }

        return $response;
    }

    /**
     * For the page: the zones covered, the rules (planned and in UniFi),
     * Block rules checked before ours, zones with Block rules the list does
     * not cover, and for each address the Block rules it is exempted from.
     *
     * @param  list<array<string, mixed>>  $zones
     * @param  list<array<string, mixed>>  $live
     * @return array<string, mixed>
     */
    private function plan(FirewallList $list, FirewallListPusher $pusher, FirewallEvaluator $firewall, array $zones, array $live): array
    {
        $covered = $list->all_zones ? $pusher->ruleZones($list, $zones) : collect($zones)->whereIn('id', $list->rule_zones ?? [])->values()->all();
        $coveredIds = array_column($covered, 'id');
        $external = collect($zones)->first(fn (array $zone) => strtolower($zone['name']) === 'external');
        $uncovered = collect($zones)->reject(fn (array $zone) => in_array($zone['id'], $coveredIds, true) || in_array(strtolower($zone['name']), ['external', 'gateway'], true))
            ->filter(fn (array $zone) => collect($live)->contains(fn (array $rule) => $rule['enabled'] && $rule['source'] === $zone['id'] && $rule['destination'] === ($external['id'] ?? null) && in_array($rule['action'], ['BLOCK', 'REJECT'], true)))
            ->pluck('name')->values()->all();

        // For each address: the block rules the Allow rules exempt it from, and the source-limited blocks they open it
        // for too (a zone-wide Allow rule lets through networks another rule keeps off the internet). With source
        // networks or "just above the matching blocks", those source-limited rules still come first.
        $effects = [];
        $ours = collect($list->rules ?? [])->pluck('name')->all();
        foreach (array_slice($list->desiredEntries()['entries'], 0, 40) as $entry) {
            $address = explode('/', $entry)[0];
            $evaluations = collect($covered)->map(fn (array $zone) => $firewall->evaluate($address, $zone['id']));
            $effects[$entry] = [
                'exempts' => $evaluations->flatMap(fn (array $evaluation) => collect($evaluation['blocks'])->reject(fn (array $block) => in_array($block['name'], $ours, true))
                    ->map(fn (array $block) => $block['name'].' ('.$block['via'].')'))->unique()->values()->all(),
                'opens' => $list->source_networks || $list->placement === 'above_blocks' ? [] : $evaluations->flatMap(fn (array $evaluation) => collect($evaluation['source_scoped'])
                    ->filter(fn (array $rule) => in_array($rule['action'], ['BLOCK', 'REJECT', 'DROP'], true))
                    ->map(fn (array $rule) => "{$rule['source']} (which {$rule['name']} otherwise blocks)"))->unique()->values()->all(),
            ];
        }

        return [
            'members' => collect($covered)->flatMap(fn (array $zone) => collect($zone['members'] ?? [])->map(fn (array $member) => $member + ['zone' => $zone['name']]))->values()->all(),
            'rule_details' => $this->ruleDetails($list, $live, $zones),
            'covered' => $covered,
            'uncovered' => $uncovered,
            'rules' => rescue(fn () => $pusher->wantedRules($list, $list->groups ?: [['id' => '', 'name' => $list->name]], $zones), [], false),
            'ahead' => $pusher->rulesAhead($list, $live),
            'live' => collect($live)->whereIn('id', collect($list->rules ?? [])->pluck('id'))->keyBy('id')->all(),
            'effects' => $effects,
            'pending' => rescue(fn () => $pusher->pendingReason($list), null, false),
            'feed_url' => $list->feed_token ? route('firewall.allow.plain', $list->feed_token) : null,
        ];
    }
}
