@props(['check', 'kind' => 'allow', 'lists' => collect(), 'name' => 'firewall'])
{{-- Outside the content filter: DNS, connection and UniFi firewall, per hostname. Offers the firewall allow list for a site the firewall blocks. --}}
@php($hosts = collect($check['hosts'] ?? []))
@if($hosts->isNotEmpty())
    <h3>DNS and firewall</h3>
    <ul class="check-list firewall-check">
        @foreach($hosts as $host => $result)
            @php($tone = match ($result['verdict']) { 'allowed' => 'ok', 'firewall', 'dns_missing', 'unreachable' => ($kind === 'allow' ? 'warn' : 'ok'), default => '' })
            <li>
                <span class="network-status {{ $tone }}">{{ $kind === 'block' && $result['verdict'] === 'firewall' ? $host.' is already blocked by the firewall rule '.$result['rule'].'. A domain block still covers its other addresses and DNS, so add it only if you mean to.' : $result['summary'] }}</span>
                @if($result['allow_lists'] && $kind === 'block')
                    <p class="network-status warn">Its address is on the firewall allow list {{ implode(', ', $result['allow_lists']) }}: a domain block can be bypassed by visiting the address directly.</p>
                    @foreach($lists->whereIn('name', $result['allow_lists']) as $list)
                        <label class="choice compact"><input type="checkbox" name="{{ $name }}_unallow[]" value="{{ $list->id }}:{{ $host }}"><span>Also remove {{ $host }} from {{ $list->name }}</span></label>
                    @endforeach
                @endif
                @if($kind === 'allow' && in_array($result['verdict'], ['firewall', 'unreachable'], true) && $lists->isNotEmpty())
                    <label class="choice compact"><input type="checkbox" name="{{ $name }}_hosts[]" value="{{ $host }}" @checked($result['verdict'] === 'firewall')><span><strong>Also add {{ $host }} to the firewall allow list</strong><small>Its addresses ({{ implode(', ', collect($result['networks'])->flatMap(fn ($network) => collect($network['addresses'])->pluck('address'))->unique()->all()) }}) are let through the firewall. That allows every site on them.</small></span></label>
                @endif
                <details class="hint"><summary>Per network</summary>
                    <ul>@foreach($result['networks'] as $network)
                        <li><strong>{{ $network['network'] }}</strong>{{ $network['zone'] ? ' (zone '.$network['zone'].')' : '' }}: @if($network['blocked'])sinkholed by DNS @elseif(!$network['addresses']){{ $network['error'] === 'NXDOMAIN' || ! $network['error'] ? "doesn't resolve" : $network['error'] }} @else @foreach($network['addresses'] as $row){{ $row['address'] }}{{ $row['connect'] ? ' · connection '.$row['connect'] : '' }}{{ $row['evaluation'] ? ' · '.\App\Services\Policy\FirewallEvaluator::describe($row['evaluation'], $row['address']) : '' }}@if(!$loop->last); @endif @endforeach @endif <small>{{ $network['resolver'] }}</small></li>
                    @endforeach</ul>
                </details>
            </li>
        @endforeach
    </ul>
    @if($kind === 'allow' && $hosts->contains(fn ($result) => in_array($result['verdict'], ['firewall', 'unreachable'], true)))
        @if($lists->isNotEmpty())
            <label>Firewall allow list<select name="{{ $name }}_list_id">@foreach($lists as $list)<option value="{{ $list->id }}">{{ $list->name }}</option>@endforeach</select></label>
            <label>Reason, if the address is shared <span class="optional">needed for CDN and shared hosting addresses</span><input name="{{ $name }}_reason" maxlength="300"></label>
        @else
            <p class="hint">To let it through the firewall, an admin can create a <a href="{{ route('admin.policy.firewall-allow.index') }}">firewall allow list</a>.</p>
        @endif
    @endif
    @if($check['skipped'] ?? [])<p class="hint">Not checked (only {{ \App\Services\Policy\SiteFirewallCheck::LIMIT }} at a time): {{ implode(', ', $check['skipped']) }}.</p>@endif
@endif
