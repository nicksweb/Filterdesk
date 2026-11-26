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
            @php($entries = collect($list->allow_entries ?? []))
            @php($flagged = $entries->filter(fn ($entry) => ! empty($entry['flags'])))
            @if($flagged->isNotEmpty())
                <div class="alert error"><strong>To check ({{ $flagged->count() }}):</strong>
                    <ul>@foreach($flagged as $entry)@foreach($entry['flags'] as $flag)<li>{{ $flag['text'] }}</li>@endforeach @endforeach</ul>
                </div>
            @endif
            @if($entries->isNotEmpty())
                {{-- One row per address, with every entry that lets it through underneath: removing one entry keeps an address another still needs. --}}
                @php($rows = collect($desired['entries'])->map(fn ($address) => ['address' => $address, 'entries' => $entries->filter(fn ($entry) => ($entry['type'] ?? null) === 'domain' ? array_key_exists($address, $entry['addresses'] ?? []) : $entry['value'] === $address)->values()]))
                @php($unresolved = $entries->filter(fn ($entry) => ($entry['type'] ?? null) === 'domain' && empty($entry['addresses'])))
                <form method="post" action="{{ route('admin.policy.firewall-allow.remove', $list) }}">@csrf @method('DELETE')
                    <div class="table-wrap compact"><table>
                        <thead><tr><th>Address</th><th>From</th><th>What the Allow rules do for it</th></tr></thead>
                        <tbody>
                            @foreach($rows as $row)
                                @php($effect = $plan['effects'][$row['address']] ?? ['exempts' => [], 'opens' => []])
                                <tr>
                                    <td><strong>{{ $row['address'] }}</strong></td>
                                    <td>
                                        @foreach($row['entries'] as $entry)
                                            @php($expired = !empty($entry['expires_at']) && now()->gte($entry['expires_at']))
                                            <label class="choice compact allow-source @if($expired) muted-row @endif"><input type="checkbox" name="entries[]" value="{{ $entry['id'] }}" aria-label="Select {{ $entry['value'] }}">
                                                <span><strong>{{ ($entry['type'] ?? null) === 'domain' ? $entry['value'].(! empty($entry['aliases']) ? ' (and '.implode(', ', $entry['aliases']).')' : '') : 'Added by hand' }}</strong>
                                                    <small>{{ collect([$entry['note'] ?? null, ! empty($entry['request']) ? 'Request '.$entry['request'] : null, $entry['added_by'] ?? null, ! empty($entry['added_at']) ? \Illuminate\Support\Carbon::parse($entry['added_at'])->local()->format('j M Y') : null,
                                                        $expired ? 'Expired' : (! empty($entry['expires_at']) ? 'until '.\Illuminate\Support\Carbon::parse($entry['expires_at'])->local()->format('j M Y g:i a') : null)])->filter()->join(' · ') }}</small>
                                                    @foreach($entry['flags'] ?? [] as $flag)<small class="network-status warn">{{ $flag['text'] }}</small>@endforeach
                                                </span></label>
                                        @endforeach
                                        @if($row['entries']->isEmpty())<span class="hint">A subnet entry covers it</span>@endif
                                    </td>
                                    <td>@if($effect['exempts'])<small><strong>Exempts it from:</strong> <x-host-list :items="$effect['exempts']" :max="3" /></small>@else<small class="hint">No block rule for these zones matches it.</small>@endif
                                        @if($effect['opens'])<small class="network-status warn"><strong>Also opens it for</strong> <x-host-list :items="$effect['opens']" :max="2" />. Limit the source networks or place the rules above the matching blocks (Zones, below) to keep those blocks.</small>@endif</td>
                                </tr>
                            @endforeach
                            @foreach($unresolved as $entry)
                                <tr class="muted-row"><td><span class="hint">Not resolved yet</span></td><td><label class="choice compact"><input type="checkbox" name="entries[]" value="{{ $entry['id'] }}"><span><strong>{{ $entry['value'] }}</strong>@foreach($entry['flags'] ?? [] as $flag)<small class="network-status warn">{{ $flag['text'] }}</small>@endforeach</span></label></td><td></td></tr>
                            @endforeach
                        </tbody>
                    </table></div>
                    <div class="button-row">
                        <button class="button small secondary" data-confirm="Remove the ticked entries from {{ $list->name }}? An address nothing else lets through is blocked again, at the next push, by any rule that blocks it.">Remove ticked</button>
                        <small class="hint">Checked every night{{ $list->verified_at ? ', last '.$list->verified_at->diffForHumans() : '' }}: whether each domain still resolves, has moved to new addresses, and whether a firewall rule still blocks each address.</small>
                    </div>
                </form>
                <form method="post" action="{{ route('admin.policy.firewall-allow.verify', $list) }}" class="button-row">@csrf<button class="button small secondary">Check the entries now</button></form>
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
                <h4>Which devices</h4>
                <label class="choice compact"><input type="radio" name="source" value="zone" @checked(! $list->source_networks)><span><strong>Every network in those zones</strong><small>Simplest. A network another rule keeps off the internet (servers, management) is let through to these addresses too.</small></span></label>
                <label class="choice compact"><input type="radio" name="source" value="networks" @checked((bool) $list->source_networks)><span><strong>Only these networks</strong><small>Each zone's Allow rule names its ticked networks; a zone with none ticked gets no rule.</small></span></label>
                <div class="category-grid">
                    @foreach($plan['members'] as $member)
                        <label class="choice compact"><input type="checkbox" name="networks[]" value="{{ $member['id'] }}" @checked(in_array($member['id'], $list->source_networks ?? [], true))><span>{{ $member['name'] }}<small>{{ $member['zone'] }}{{ $member['vlan'] ? ' · VLAN '.$member['vlan'] : '' }}</small></span></label>
                    @endforeach
                </div>
                <h4>Where the rules go</h4>
                <label class="choice compact"><input type="radio" name="placement" value="top" @checked(($list->placement ?? 'top') === 'top')><span><strong>At the top of each zone's order</strong><small>Ahead of every block rule. Invalid connections are never let through either way.</small></span></label>
                <label class="choice compact"><input type="radio" name="placement" value="above_blocks" @checked($list->placement === 'above_blocks')><span><strong>Just above the block rules that match these addresses</strong><small>The rules above them (general blocks, and blocks for single networks or devices) are still checked first, the way you'd place one by hand.</small></span></label>
                @if($plan['uncovered'])<p class="network-status warn">Zones with block rules this list doesn't cover: {{ implode(', ', $plan['uncovered']) }}. Addresses in it stay blocked there.</p>@endif
                @if($plan['ahead'])<p class="network-status warn">Block rules checked before this list's Allow rules: @foreach($plan['ahead'] as $rule)<strong>{{ $rule['name'] }}</strong> (above {{ $rule['before'] }})@if(!$loop->last), @endif @endforeach. Push again to move the Allow rules back to the top.</p>@endif
                @if($plan['rule_details'])
                    <x-firewall-rules :rules="$plan['rule_details']" />
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
