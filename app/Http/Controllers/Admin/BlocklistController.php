<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EntrySource;
use App\Enums\ListKind;
use App\Enums\ListScope;
use App\Http\Controllers\Controller;
use App\Jobs\RunManualPush;
use App\Models\FilterPolicy;
use App\Models\FirewallList;
use App\Models\ListSource;
use App\Models\PolicyEntry;
use App\Models\PolicyList;
use App\Services\AuditLogger;
use App\Services\Network\ProviderRequestFailed;
use App\Services\Policy\ControllerPusher;
use App\Services\Policy\FeedFetcher;
use App\Services\Policy\FeedParser;
use App\Services\Policy\FirewallListPusher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * Third-party blocklists (domains into policy lists, IPs into firewall IP
 * groups) and the firewall IP lists themselves.
 */
class BlocklistController extends Controller
{
    public function index(FirewallListPusher $firewall): View
    {
        $groups = [];
        $zones = [];
        $liveRules = [];
        $groupsError = null;
        if ($manager = $firewall->manager()) {
            try {
                $groups = $manager->ipGroups();
                $zones = $manager->firewallZones();
                $liveRules = $manager->firewallRules();
            } catch (ProviderRequestFailed $exception) {
                $groupsError = $exception->getMessage();
            } catch (Throwable $exception) {
                report($exception);
                $groupsError = 'The controller could not be reached.';
            }
        }

        $feedCounts = PolicyEntry::query()->where('source', EntrySource::Feed)->select('policy_list_id', DB::raw('count(*) as total'))->groupBy('policy_list_id')->pluck('total', 'policy_list_id');
        $lists = PolicyList::query()->whereIn('id', $feedCounts->keys())->get();

        return view('admin.policy.blocklists', [
            'sources' => ListSource::query()->with('policyList', 'firewallList')->orderBy('type')->orderBy('name')->get(),
            'firewallLists' => FirewallList::query()->withCount('sources')->orderBy('name')->get(),
            'presets' => collect(config('blocklists.presets')),
            'policies' => FilterPolicy::query()->ordered()->get(),
            'groups' => $groups,
            'zones' => $zones,
            'rulePlans' => FirewallList::query()->get()->mapWithKeys(fn (FirewallList $list) => [$list->id => [
                'rules' => rescue(fn () => $firewall->wantedRules($list, $list->groups ?: [['id' => '', 'name' => $list->name]], $zones,
                    $list->rule_zones ?? collect($zones)->where(fn (array $zone) => strtolower($zone['name']) === 'internal')->pluck('id')->all()), [], false),
                'ahead' => $firewall->rulesAhead($list, $liveRules),
                'live' => collect($liveRules)->whereIn('id', collect($list->rules ?? [])->pluck('id'))->keyBy('id')->all(),
            ]])->all(),
            'firewallStatus' => FirewallList::query()->pluck('id')->mapWithKeys(fn (int $id) => [$id => Cache::get(RunManualPush::statusKey('firewall', $id))])->filter()->all(),
            'groupLimit' => $firewall->groupLimit(),
            'groupsError' => $groupsError,
            'canWrite' => $firewall->canWrite(),
            'maxFilterEntries' => app(ControllerPusher::class)->maxEntries(),
            'feedLoad' => FilterPolicy::query()->where('enabled', true)->ordered()->get()->map(fn (FilterPolicy $policy) => [
                'policy' => $policy,
                // Once a policy has a layer, its block feeds go to the layer instead.
                'count' => $lists->filter(fn (PolicyList $list) => $list->appliesTo($policy)
                    && ($policy->isLayer() ? $list->kind === ListKind::Block : ! ($policy->activeLayer() && $list->kind === ListKind::Block)))
                    ->sum(fn (PolicyList $list) => $feedCounts[$list->id] ?? 0),
            ]),
        ]);
    }

    public function storeFeed(Request $request, FeedFetcher $fetcher, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'preset' => ['nullable', Rule::in(array_keys(config('blocklists.presets')))],
            'name' => ['nullable', 'required_without:preset', 'string', 'max:120'],
            'url' => ['nullable', 'required_without:preset', 'url', 'starts_with:https://', 'max:2048'],
            'type' => ['nullable', 'required_without:preset', 'in:domains,ips'],
            'kind' => ['nullable', Rule::enum(ListKind::class)],
            'scope' => ['nullable', Rule::enum(ListScope::class)],
            'audience' => ['nullable', 'required_if:scope,audience', Rule::exists('network_types', 'key')],
            'filter_policy_id' => ['nullable', 'required_if:scope,policy', 'exists:filter_policies,id'],
            'firewall_list_id' => ['nullable', 'exists:firewall_lists,id'],
            'refresh_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
            'max_entries' => ['nullable', 'integer', 'min:10', 'max:'.config('blocklists.max_entries_limit')],
        ]);

        $preset = isset($validated['preset']) ? config("blocklists.presets.{$validated['preset']}") : null;
        $type = $preset['type'] ?? $validated['type'];
        $name = $preset['name'] ?? $validated['name'];
        $url = $preset['url'] ?? $validated['url'];
        if (ListSource::query()->where('url', $url)->exists()) {
            throw ValidationException::withMessages(['url' => 'That list is already added.']);
        }

        $source = DB::transaction(function () use ($validated, $type, $name, $url, $preset): ListSource {
            $attributes = [
                'name' => $name, 'preset' => $validated['preset'] ?? null, 'url' => $url, 'type' => $type,
                'refresh_hours' => (int) ($validated['refresh_hours'] ?? 24),
                'max_entries' => (int) ($validated['max_entries'] ?? ($type === 'ips' ? 4000 : 20000)),
            ];

            if ($type === 'ips') {
                $firewall = isset($validated['firewall_list_id'])
                    ? FirewallList::query()->findOrFail($validated['firewall_list_id'])
                    : FirewallList::query()->create(['name' => $name, 'family' => 'ipv4']);

                return ListSource::query()->create($attributes + ['firewall_list_id' => $firewall->id]);
            }

            $scope = ListScope::from($validated['scope'] ?? ListScope::Site->value);
            $kind = ListKind::from($validated['kind'] ?? ListKind::Block->value);
            $list = PolicyList::query()->create([
                'name' => $name, 'kind' => $kind, 'scope' => $scope,
                'audience' => $scope === ListScope::Audience ? $validated['audience'] : null,
                'filter_policy_id' => $scope === ListScope::Policy ? $validated['filter_policy_id'] : null,
                'description' => 'Filled automatically from '.($preset ? $preset['name'] : $url).'. Approvals and lists for one network type or policy still override it.',
                'is_default' => false, 'sort_order' => 900,
            ]);

            return ListSource::query()->create($attributes + ['policy_list_id' => $list->id]);
        });

        $audit->log('policy.feed_added', $source, ['url' => $url, 'type' => $type]);
        $result = $fetcher->refresh($source->load('firewallList'), force: true);

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function updateFeed(Request $request, ListSource $source, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'refresh_hours' => ['required', 'integer', 'min:1', 'max:168'],
            'max_entries' => ['required', 'integer', 'min:10', 'max:'.config('blocklists.max_entries_limit')],
        ]);
        $source->update(['enabled' => $request->boolean('enabled'), 'refresh_hours' => $validated['refresh_hours'], 'max_entries' => $validated['max_entries']]);
        if (! $source->enabled && $source->policy_list_id) {
            PolicyEntry::query()->where('list_source_id', $source->id)->delete();
            $source->forceFill(['entry_count' => 0, 'etag' => null, 'last_modified' => null])->save();
        }
        $audit->log('policy.feed_updated', $source, ['enabled' => $source->enabled]);

        return back()->with('success', $source->enabled ? "{$source->name} updated." : "{$source->name} is off and its entries were removed.");
    }

    public function refreshFeed(ListSource $source, FeedFetcher $fetcher): RedirectResponse
    {
        $result = $fetcher->refresh($source->load('firewallList'), force: true);

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function destroyFeed(ListSource $source, AuditLogger $audit): RedirectResponse
    {
        $audit->log('policy.feed_removed', $source, ['name' => $source->name, 'url' => $source->url]);
        DB::transaction(function () use ($source): void {
            $list = $source->policyList;
            $source->delete();
            $list?->delete();
        });

        return back()->with('success', "{$source->name} and its entries were removed. Push the affected policies to take them out of the controller.");
    }

    public function storeFirewallList(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:120'], 'family' => ['required', 'in:ipv4,ipv6']]);
        $list = FirewallList::query()->create($validated);
        $audit->log('firewall.list_created', $list);

        return back()->with('success', "{$list->name} created. Link it to a controller IP group, or create one, then push.");
    }

    public function updateFirewallList(Request $request, FirewallList $list, FeedParser $parser, FirewallListPusher $pusher, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'manual_entries' => ['nullable', 'string', 'max:200000'],
            'provider_reference' => ['nullable', 'string', 'max:190'],
            'max_entries' => ['required', 'integer', 'min:1', 'max:'.config('blocklists.max_entries_limit')],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $entries = [];
        $invalid = [];
        foreach (preg_split('/[\r\n,]+/', $validated['manual_entries'] ?? '') ?: [] as $line) {
            if (trim($line) === '' || str_starts_with(trim($line), '#')) {
                continue;
            }
            $value = $parser->ip($line, $list->family);
            $value === null ? $invalid[] = trim($line) : $entries[$value] = true;
        }
        if ($invalid) {
            throw ValidationException::withMessages(['manual_entries' => 'Not valid '.strtoupper($list->family).' addresses or subnets: '.implode(', ', array_slice($invalid, 0, 10))]);
        }

        $group = collect($pusher->manager()?->ipGroups() ?? [])->firstWhere('id', $validated['provider_reference'] ?? null);
        if (filled($validated['provider_reference'] ?? null) && $group && $group['family'] !== $list->family) {
            throw ValidationException::withMessages(['provider_reference' => 'That controller group holds '.strtoupper($group['family']).' addresses.']);
        }

        $list->update([
            'name' => $validated['name'], 'manual_entries' => array_keys($entries), 'max_entries' => $validated['max_entries'],
            'enabled' => $request->boolean('enabled', true),
            'provider_reference' => $validated['provider_reference'] ?: null,
            'provider_name' => $group['name'] ?? ($validated['provider_reference'] ? $list->provider_name : null),
        ]);
        // Choosing a different first group re-links the list; the app's other groups stay as they are.
        $first = $list->groups[0]['id'] ?? null;
        if (($validated['provider_reference'] ?? null) !== $first) {
            $list->forceFill([
                'groups' => filled($validated['provider_reference'] ?? null) ? [['id' => $validated['provider_reference'], 'name' => $list->provider_name, 'count' => (int) ($group['count'] ?? 0)]] : null,
                'pushed_hash' => null,
            ])->save();
        }
        $audit->log('firewall.list_updated', $list, ['manual' => count($entries)]);

        return back()->with('success', "{$list->name} saved. Push it to update the controller.");
    }

    public function createGroup(FirewallList $list, FirewallListPusher $pusher): RedirectResponse
    {
        if (! $pusher->canWrite()) {
            return back()->with('error', 'Set the network provider to Active to create IP groups in the controller.');
        }
        $result = RunManualPush::start('firewall', $list->id, request()->user(), force: true);

        return RunManualPush::flash(back(), $result, "Creating the IP group(s) for {$list->name} in UniFi. It runs in the background; refresh in a minute, then create the blocking rules.");
    }

    /** Choose the zones whose traffic to these addresses is blocked; the app creates and keeps the rules. */
    public function rules(Request $request, FirewallList $list, FirewallListPusher $pusher, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'zones' => ['nullable', 'array', 'max:10'], 'zones.*' => ['string', 'max:64'],
            'block_incoming' => ['nullable', 'boolean'],
            'rules_first' => ['nullable', 'boolean'],
        ]);
        if (! $pusher->canWrite()) {
            return back()->with('error', 'Set the network provider to Active to create firewall rules.');
        }
        $zones = array_values(array_intersect($validated['zones'] ?? [], collect(rescue(fn () => $pusher->manager()->firewallZones(), [], false))->pluck('id')->all()));
        $list->forceFill(['rule_zones' => $zones ?: null, 'block_incoming' => $zones !== [] && $request->boolean('block_incoming'), 'rules_first' => $zones !== [] && $request->boolean('rules_first')])->save();
        $audit->log('firewall.rules_chosen', $list, ['zones' => $zones, 'incoming' => $list->block_incoming, 'first' => $list->rules_first]);
        $result = RunManualPush::start('firewall', $list->id, $request->user(), force: true);

        return RunManualPush::flash(back(), $result, ($zones ? 'Updating the blocking rules' : 'Removing the blocking rules')." for {$list->name} in UniFi. It runs in the background; refresh in a minute to see the result.");
    }

    public function pushFirewallList(Request $request, FirewallList $list, FirewallListPusher $pusher): RedirectResponse
    {
        if (! $pusher->canWrite()) {
            return back()->with('error', 'Pushing is off. Set the network provider to Active under Settings → Network provider.');
        }
        $result = RunManualPush::start('firewall', $list->id, $request->user(), force: true);

        return RunManualPush::flash(back(), $result, "Pushing {$list->name} to UniFi. It runs in the background; refresh in a minute to see the result.");
    }

    public function destroyFirewallList(FirewallList $list, FirewallListPusher $pusher, AuditLogger $audit): RedirectResponse
    {
        // Rules this app created would otherwise keep blocking a list nobody manages.
        if ($pusher->canWrite()) {
            $pusher->removeRules($list);
        }
        $audit->log('firewall.list_deleted', $list, ['name' => $list->name]);
        $list->sources()->delete();
        $list->delete();

        return back()->with('success', 'Removed, along with the blocking rules this app created. The IP groups were left in UniFi; delete them there if they are no longer used.');
    }
}
