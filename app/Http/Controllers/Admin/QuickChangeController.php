<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ListKind;
use App\Http\Controllers\Controller;
use App\Jobs\RunManualPush;
use App\Models\FirewallList;
use App\Models\NetworkType;
use App\Services\Policy\ControllerPusher;
use App\Services\Policy\FirewallAllowList;
use App\Services\Policy\FirewallListPusher;
use App\Services\Policy\PolicyEngine;
use App\Services\Policy\ProtectedSites;
use App\Services\Policy\QuickChange;
use App\Services\Policy\SiteFirewallCheck;
use App\Services\SettingsRepository;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Quick block or allow: hostnames, the network types (or everywhere), when,
 * then a preview of the lists, filters, conflicting entries and categories
 * before anything changes.
 */
class QuickChangeController extends Controller
{
    public function show(Request $request): View
    {
        return $this->page(['domains' => (string) $request->query('domains', ''), 'kind' => $request->query('kind') === 'allow' ? 'allow' : 'block', 'types' => [], 'when' => 'all']);
    }

    public function preview(Request $request, QuickChange $quick): View
    {
        $input = $this->validated($request);
        $plan = $quick->plan($input);
        if ($plan['domains'] === []) {
            throw ValidationException::withMessages(['domains' => $plan['protected'] ? ProtectedSites::message($plan['protected']) : 'Enter at least one hostname.']);
        }

        // What else could block it: DNS, the connection, and UniFi's firewall rules for the address.
        $plan['firewall'] = rescue(fn () => app(SiteFirewallCheck::class)->check($plan['domains'], $plan['scope'], connect: $plan['kind'] === ListKind::Allow), [], false);

        return $this->page($input, $plan);
    }

    public function apply(Request $request, QuickChange $quick, ControllerPusher $pusher, SettingsRepository $settings): RedirectResponse
    {
        $input = $this->validated($request);
        $extra = $request->validate([
            'remove' => ['nullable', 'array', 'max:500'], 'remove.*' => ['integer'],
            'firewall_hosts' => ['nullable', 'array', 'max:20'], 'firewall_hosts.*' => ['string', 'max:253'],
            'firewall_list_id' => ['nullable', 'integer'],
            'firewall_reason' => ['nullable', 'string', 'max:300'],
            'firewall_unallow' => ['nullable', 'array', 'max:20'], 'firewall_unallow.*' => ['string', 'max:300'],
            'note' => ['nullable', 'string', 'max:500'],
            'expires_at' => ['nullable', 'date'],
        ]);
        $expiresAt = $settings->parseLocal($extra['expires_at'] ?? null);
        if ($expiresAt?->isPast()) {
            throw ValidationException::withMessages(['expires_at' => 'The end must be in the future.']);
        }
        $plan = $quick->plan($input);
        if ($plan['domains'] === [] || $plan['lists']->isEmpty()) {
            return redirect()->route('admin.policy.quick.show')->with('error', 'Nothing to change: no list takes these hostnames for the chosen networks.');
        }

        $counts = $quick->apply($plan, array_map('intval', $extra['remove'] ?? []), $request->user(), $extra['note'] ?? null, $expiresAt);
        $verb = $plan['kind']->value === 'block' ? 'Blocked' : 'Allowed';
        $messages = [];
        if ($counts['added']) {
            $messages[] = "{$verb} ".implode(', ', $plan['domains']).($plan['when'] === 'school' ? ' in school hours only' : ($plan['when'] === 'outside' ? ' outside school hours only' : '')).' in '.$plan['lists']->pluck('name')->join(', ').'.';
        }
        if ($counts['proposed']) {
            $messages[] = 'Site-wide changes wait for a second IT officer under Changes.';
        }
        if ($counts['removed'] || $counts['proposed_removals']) {
            $messages[] = $counts['removed'].' conflicting entr'.($counts['removed'] === 1 ? 'y' : 'ies').' removed'.($counts['proposed_removals'] ? ", {$counts['proposed_removals']} proposed for removal" : '').'.';
        }

        $messages = [...$messages, ...$this->firewallChanges($extra, $plan, $request, $expiresAt)];

        $published = false;
        if ($counts['added'] + $counts['removed'] > 0 && $pusher->automatic()) {
            RunManualPush::start('release', null, $request->user());
            $messages[] = 'Publishing to UniFi in the background (automatic pushing is on).';
            $published = true;
        }

        return redirect()->route('admin.policy.quick.show')->with('success', implode(' ', $messages) ?: 'Nothing changed.')
            ->with('quick_publish', ! $published && $counts['added'] + $counts['removed'] > 0 && $pusher->enabled());
    }

    /**
     * The firewall side of a quick change: add the ticked hostnames to an
     * allow list (with the same expiry), or take them off one.
     *
     * @param  array<string, mixed>  $extra
     * @param  array<string, mixed>  $plan
     * @return list<string>
     */
    private function firewallChanges(array $extra, array $plan, Request $request, ?CarbonInterface $expiresAt): array
    {
        $allow = app(FirewallAllowList::class);
        $messages = [];
        $hosts = array_values(array_intersect($extra['firewall_hosts'] ?? [], $plan['domains']));
        $list = $hosts ? FirewallList::query()->where('kind', 'allow')->find($extra['firewall_list_id'] ?? null) : null;
        if ($hosts && $list && $plan['kind'] === ListKind::Allow) {
            $result = $allow->add($list, $hosts, $request->user(), $extra['note'] ?? 'Quick allow by '.$request->user()->name, null, $expiresAt?->toIso8601String(), $extra['firewall_reason'] ?? null);
            if ($result['added']) {
                $messages[] = 'Added to the firewall allow list '.$list->name.': '.implode(', ', $result['added']).'.';
                $this->pushFirewall($list, $request);
            }
            if ($result['refused']) {
                $messages[] = 'Not added to the firewall allow list: '.implode(' ', $result['refused']);
            }
        }
        foreach ($extra['firewall_unallow'] ?? [] as $pair) {
            [$id, $host] = explode(':', $pair, 2) + [1 => ''];
            $unallowList = FirewallList::query()->where('kind', 'allow')->find((int) $id);
            if ($unallowList && in_array($host, $plan['domains'], true) && $allow->removeValues($unallowList, [$host], $request->user())) {
                $messages[] = "Removed {$host} from the firewall allow list {$unallowList->name}.";
                $this->pushFirewall($unallowList, $request);
            }
        }

        return $messages;
    }

    private function pushFirewall(FirewallList $list, Request $request): void
    {
        if ($list->provider_reference && app(FirewallListPusher::class)->canWrite()) {
            RunManualPush::start('firewall', $list->id, $request->user());
        }
    }

    /** @return array{domains: string, kind: string, types: list<string>, when: string, include_www: bool} */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'domains' => ['required', 'string', 'max:4000'],
            'kind' => ['required', 'in:allow,block'],
            'types' => ['required', 'array', 'min:1'],
            'types.*' => ['string', Rule::in([...NetworkType::query()->pluck('key')->all(), 'site'])],
            'when' => ['nullable', 'in:all,school,outside'],
            'include_www' => ['nullable', 'boolean'],
        ], ['types.required' => 'Choose the networks it is for, or Everywhere.']);

        return ['domains' => $validated['domains'], 'kind' => $validated['kind'], 'types' => array_values($validated['types']), 'when' => $validated['when'] ?? 'all', 'include_www' => $request->boolean('include_www')];
    }

    /** @param array<string, mixed>|null $plan */
    private function page(array $input, ?array $plan = null): View
    {
        $engine = app(PolicyEngine::class);
        $types = NetworkType::query()->ordered()->get();

        return view('admin.policy.quick', [
            'allowLists' => FirewallList::query()->where('kind', 'allow')->where('enabled', true)->orderBy('id')->get(),
            'input' => $input,
            'plan' => $plan,
            'types' => $types,
            'hoursLayers' => $types->mapWithKeys(fn (NetworkType $type) => [$type->key => $engine->hoursLayersFor($type->key)])->filter(fn ($layers) => $layers->isNotEmpty()),
        ]);
    }
}
