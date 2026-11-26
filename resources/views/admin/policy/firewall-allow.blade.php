<x-layouts.app title="Firewall allow lists">
    <x-slot:head><link rel="stylesheet" href="{{ asset('admin.css') }}"></x-slot:head>

    <div class="page-heading">
        <div>
            <p class="eyebrow">Web filtering</p>
            <h1>Firewall allow lists</h1>
            <p>Addresses let through UniFi's firewall, for sites whose address a firewall rule blocks (a content filter allow can't open those). Each list keeps a UniFi IP list with its addresses, and Allow rules that sit above the block rules.</p>
        </div>
        <a class="button secondary" href="{{ route('admin.policy.blocklists.index') }}">Blocklists &amp; firewall</a>
    </div>

    <details class="panel policy-section">
        <summary><strong>How it works with zones</strong></summary>
        <p>UniFi's zone-based firewall checks traffic <strong>per pair of zones</strong>, for example <em>Internal → External</em>, and uses the first matching rule in that pair's order. Each network belongs to one zone: Internal unless you moved it to a zone of its own (for example Staff, Students or CCTV), Hotspot for guest networks, Vpn for VPN clients.</p>
        <p>So an allow list needs <strong>one Allow rule per zone</strong>: <em>Allow · &lt;zone&gt; → External · destination in the list</em>. Filterdesk creates them and moves them to the <strong>top of each zone's order</strong>, above every block rule (its own block lists' rules too), so an allowed address gets through even when it is also in a blocked range.</p>
        <p><strong>Every zone</strong> (the default) covers all zones except External and Gateway, and follows UniFi: when you create a new zone and move networks into it, the next push (hourly, or when the list changes) adds its Allow rule. <strong>Chosen zones</strong> limits it, for example to staff networks only; a zone you create later is not covered until you tick it, and the page lists zones that have block rules but no Allow rule from this list.</p>
        <p>Allowing an address allows <strong>every site on that address</strong>. Shared hosting and CDN addresses (Cloudflare, Fastly) and subnets wider than /24 need a reason, and anything wider than /16 is refused. Domains are resolved through the probes, so on the school's own DNS, and checked again every hour; an address a domain stops using is dropped after {{ \App\Services\Policy\FirewallAllowList::GRACE_DAYS }} days.</p>
        <p>Threat management (IPS), region blocking and the content filter are separate from these rules: an allow here doesn't lift them.</p>
    </details>

    @if($error)<div class="alert error">{{ $error }}</div>@endif

    @if($unused)
        <div class="notice"><strong>UniFi IP lists that no firewall rule uses:</strong> @foreach($unused as $group){{ $group['name'] }} ({{ number_format($group['count']) }})@if(!$loop->last), @endif @endforeach. A list does nothing on its own: if one is meant to allow or block addresses, a rule has to use it. To manage one here, create an allow list below and choose <em>Take over a UniFi list</em>.</div>
    @endif

    @forelse($lists as $list)
        @php($plan = $plans[$list->id])
        @php($desired = $list->desiredEntries())
        <section class="panel policy-section">
            <div class="panel-heading">
                <div><h2>{{ $list->name }} <small>{{ strtoupper($list->family) }} · {{ number_format($desired['total']) }} address(es) from {{ count($list->allow_entries ?? []) }} entr{{ count($list->allow_entries ?? []) === 1 ? 'y' : 'ies' }}{{ $list->provider_reference ? ' · UniFi list '.($list->provider_name ?: $list->provider_reference) : ' · not in UniFi yet' }}</small></h2>
                    @if($list->last_pushed_at)<p><span class="status status-{{ $list->last_push_status === 'failed' ? 'failed' : 'ok' }}">{{ ucfirst($list->last_push_status) }}</span> {{ $list->last_pushed_at->diffForHumans() }} · {{ $list->last_push_message }}</p>@endif
                    @if($plan['pending'] && $list->provider_reference)<p class="network-status warn">Waiting to push: {{ $plan['pending'] }}</p>@endif
                </div>
                <div class="button-row">
                    @if($canWrite && $list->provider_reference)<form method="post" action="{{ route('admin.policy.firewall-allow.push', $list) }}">@csrf<button class="button primary" data-confirm="Push {{ $list->name }}'s {{ number_format($desired['total']) }} address(es) and its Allow rules to UniFi?">Push to UniFi</button></form>@endif
                    @if($canWrite && ! $list->provider_reference)<form method="post" action="{{ route('admin.policy.blocklists.firewall.create-group', $list) }}">@csrf<button class="button primary" data-confirm="Create the UniFi IP list for {{ $list->name }}, and its Allow rules for {{ count($plan['covered']) }} zone(s)?">Create in UniFi</button></form>@endif
                </div>
            </div>
            <x-push-status :status="$firewallStatus[$list->id] ?? null" />

            <h3>Entries</h3>
            @if($list->allow_entries)
                <form method="post" action="{{ route('admin.policy.firewall-allow.remove', $list) }}">@csrf @method('DELETE')
                    <div class="table-wrap compact"><table>
                        <thead><tr><th></th><th>Address or domain</th><th>Resolves to</th><th>Why</th><th>Until</th><th>Exempts it from</th></tr></thead>
                        <tbody>
                            @foreach($list->allow_entries as $entry)
                                @php($expired = !empty($entry['expires_at']) && now()->gte($entry['expires_at']))
                                <tr @class(['muted-row' => $expired])>
                                    <td><input type="checkbox" name="entries[]" value="{{ $entry['id'] }}" aria-label="Select {{ $entry['value'] }}"></td>
                                    <td><strong>{{ $entry['value'] }}</strong><small>{{ ['ip' => 'Address', 'cidr' => 'Subnet', 'domain' => 'Domain'][$entry['type']] ?? $entry['type'] }}{{ $entry['added_by'] ? ' · '.$entry['added_by'] : '' }}{{ !empty($entry['added_at']) ? ' · '.\Illuminate\Support\Carbon::parse($entry['added_at'])->local()->format('j M Y') : '' }}</small></td>
                                    <td>@if(($entry['type'] ?? null) === 'domain')<x-host-list :items="array_keys($entry['addresses'] ?? [])" :max="4" />@if(empty($entry['addresses']))<span class="hint">Not resolved yet</span>@endif @else<span class="hint">—</span>@endif</td>
                                    <td>{{ $entry['note'] ?? '' }}@if(!empty($entry['request']))<small>Request {{ $entry['request'] }}</small>@endif</td>
                                    <td>{{ $expired ? 'Expired' : (!empty($entry['expires_at']) ? \Illuminate\Support\Carbon::parse($entry['expires_at'])->local()->format('j M Y g:i a') : 'No end') }}</td>
                                    <td>@php($addresses = ($entry['type'] ?? null) === 'domain' ? array_keys($entry['addresses'] ?? []) : [$entry['value']])
                                        @php($exempts = collect($addresses)->flatMap(fn ($address) => $plan['effects'][$address] ?? [])->unique()->values()->all())
                                        @if($exempts)<x-host-list :items="$exempts" :max="3" />@else<span class="hint">No block rule matches it</span>@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table></div>
                    <div class="button-row"><button class="button small secondary" data-confirm="Remove the ticked entries from {{ $list->name }}? Their addresses are blocked again by any rule that blocks them, at the next push.">Remove ticked</button></div>
                </form>
            @else
                <p class="hint">No entries yet.</p>
            @endif

            <form method="post" action="{{ route('admin.policy.firewall-allow.add', $list) }}" class="allow-add">@csrf
                <div class="fields two">
                    <label>Add addresses, subnets or domains <span class="optional">one per line or comma separated</span><textarea name="values" rows="3" class="mono" required placeholder="aurora.example.edu&#10;198.51.100.25&#10;203.0.113.0/28">{{ old('values') }}</textarea></label>
                    <div class="fields">
                        <label>Note <span class="optional">what it is for, or the request</span><input name="note" maxlength="300" value="{{ old('note') }}" placeholder="Staff need the partner portal"></label>
                        <label>Reason, for shared or wide addresses <span class="optional">needed for CDN, shared hosting and subnets wider than /24</span><input name="reason" maxlength="300" value="{{ old('reason') }}"></label>
                        <label>Until <span class="optional">optional</span><input type="datetime-local" name="expires_at" value="{{ old('expires_at') }}"></label>
                    </div>
                </div>
                <div class="button-row"><button class="button primary">Add</button></div>
            </form>

            <h3>Zones</h3>
            <form method="post" action="{{ route('admin.policy.firewall-allow.zones', $list) }}">@csrf
                <label class="choice compact"><input type="radio" name="scope" value="all" @checked($list->all_zones)><span><strong>Every zone</strong><small>All zones except External and Gateway, now: {{ collect($plan['covered'])->pluck('name')->join(', ') ?: 'none read' }}. A zone you create later is added at the next push.</small></span></label>
                <label class="choice compact"><input type="radio" name="scope" value="chosen" @checked(! $list->all_zones && $list->rule_zones)><span><strong>Only these zones</strong></span></label>
                <div class="category-grid">
                    @foreach(collect($zones)->reject(fn ($zone) => in_array(strtolower($zone['name']), ['external', 'gateway'], true)) as $zone)
                        <label class="choice compact"><input type="checkbox" name="zones[]" value="{{ $zone['id'] }}" @checked(in_array($zone['id'], $list->rule_zones ?? [], true))><span>{{ $zone['name'] }}<small>{{ ($zone['networks'] ?? []) ? implode(', ', $zone['networks']) : 'No networks in this zone' }}</small></span></label>
                    @endforeach
                </div>
                <label class="choice compact"><input type="radio" name="scope" value="none" @checked(! $list->all_zones && ! $list->rule_zones)><span><strong>No rules</strong><small>Keep the UniFi list only (for your own rule, or for a firewall that loads the URL below).</small></span></label>
                @if($plan['uncovered'])<p class="network-status warn">Zones with block rules this list doesn't cover: {{ implode(', ', $plan['uncovered']) }}. Addresses in it stay blocked there.</p>@endif
                @if($plan['ahead'])<p class="network-status warn">Block rules checked before this list's Allow rules: @foreach($plan['ahead'] as $rule)<strong>{{ $rule['name'] }}</strong> (above {{ $rule['before'] }})@if(!$loop->last), @endif @endforeach. Push again to move the Allow rules back to the top.</p>@endif
                @if($list->rules)
                    <p class="hint">Allow rules in UniFi: @foreach($list->rules as $rule){{ $rule['name'] }}@if(isset($plan['live'][$rule['id']]) && !$plan['live'][$rule['id']]['enabled']) <span class="status status-action_required">disabled in UniFi</span>@endif @if(!$loop->last), @endif @endforeach</p>
                @elseif($plan['rules'])
                    <p class="hint">Rules that will be created: @foreach($plan['rules'] as $rule)<code>{{ $rule['name'] }}</code> ({{ $rule['from'] }} → {{ $rule['to'] }})@if(!$loop->last), @endif @endforeach. Each goes to the top of its zone's order.</p>
                @endif
                <div class="button-row"><button class="button secondary" data-confirm="Save the zones for {{ $list->name }}? The Allow rules in UniFi are changed to match.">Save zones</button></div>
            </form>

            <h3>Other ways in</h3>
            <div class="fields two">
                <div>
                    <p><strong>Take over a UniFi list</strong> <span class="hint">Link an IP list that already exists in UniFi and bring its addresses in as entries, rather than making a second one.</span></p>
                    <form method="post" action="{{ route('admin.policy.firewall-allow.adopt', $list) }}" class="button-row">@csrf
                        <select name="group" aria-label="UniFi IP list">@foreach($groups->where('family', $list->family) as $group)<option value="{{ $group['id'] }}" @selected($list->provider_reference === $group['id'])>{{ $group['name'] }} ({{ number_format($group['count']) }})</option>@endforeach</select>
                        <button class="button small secondary" data-confirm="Manage this UniFi list from {{ $list->name }}? Its addresses become entries here, and pushes replace its contents from now on.">Take over</button>
                    </form>
                </div>
                <div>
                    <p><strong>Plain-text URL</strong> <span class="hint">One address or subnet per line, for a firewall that loads IP lists from a URL. Filterdesk couldn't confirm the format UniFi expects for its URL lists, so check it loads; a list loaded this way is refreshed on the firewall's own schedule (often every 24 hours).</span></p>
                    @if($plan['feed_url'])
                        <div class="button-row"><input readonly value="{{ $plan['feed_url'] }}" id="feed-{{ $list->id }}" class="mono"><button type="button" class="button small secondary" data-copy="#feed-{{ $list->id }}">Copy</button></div>
                        <form method="post" action="{{ route('admin.policy.firewall-allow.feed', $list) }}" class="button-row">@csrf<input type="hidden" name="on" value="1"><button class="button small secondary" data-confirm="Make a new URL? The current one stops working.">New URL</button></form>
                        <form method="post" action="{{ route('admin.policy.firewall-allow.feed', $list) }}" class="button-row">@csrf<input type="hidden" name="on" value="0"><button class="link-button">Turn the URL off</button></form>
                    @else
                        <form method="post" action="{{ route('admin.policy.firewall-allow.feed', $list) }}">@csrf<input type="hidden" name="on" value="1"><button class="button small secondary">Make a URL</button></form>
                    @endif
                </div>
            </div>
            <form method="post" action="{{ route('admin.policy.firewall-allow.destroy', $list) }}" class="button-row">@csrf @method('DELETE')<button class="link-button danger-text" data-confirm="Delete {{ $list->name }}? Its Allow rules are deleted in UniFi, so its addresses are blocked again by any rule that blocks them. The UniFi IP list is left in place.">Delete this allow list</button></form>
        </section>
    @empty
        <section class="panel policy-section"><p>No allow lists yet. Create one below, or take over a UniFi list that's already there.</p></section>
    @endforelse

    <form method="post" action="{{ route('admin.policy.firewall-allow.store') }}" class="panel inline-create">@csrf
        <div class="fields three">
            <label>New allow list<input name="name" required placeholder="Firewall allow list"></label>
            <label>Family<select name="family"><option value="ipv4">IPv4</option><option value="ipv6">IPv6</option></select></label>
            <div class="form-actions"><button class="button secondary">Create</button></div>
        </div>
    </form>
</x-layouts.app>
