<x-layouts.app title="Networks & filters">
    <x-slot:head><link rel="stylesheet" href="{{ asset('admin.css') }}"></x-slot:head>
    @php($isAdmin = auth()->user()->isAdmin())

    <div class="page-heading">
        <div>
            <p class="eyebrow">Web filtering</p>
            <h1>Networks &amp; filters</h1>
            <p>Which controller content filter each VLAN uses, and whether it gets staff, student or guest filtering here.</p>
        </div>
        <div class="button-row">
            @if(auth()->user()->isAdmin() && $available)<a class="button secondary" href="{{ route('admin.policy.setup.show') }}">Guided setup</a>@endif
            <a class="button secondary" href="{{ route('admin.policy.networks.index', ['refresh' => 1]) }}">Refresh from controller</a>
            @if($available)
                <form method="post" action="{{ route('admin.policy.networks.check') }}">@csrf<button class="button secondary">Check sync now</button></form>
                <form method="post" action="{{ route('admin.policy.networks.pull') }}">@csrf<button class="button {{ $canPush ? 'secondary' : 'primary' }}">Fetch all linked lists</button></form>
            @endif
            @if($canPush)
                <form method="post" action="{{ route('admin.policy.networks.push') }}">@csrf<button class="button primary" data-confirm="Push pending changes to every linked content filter?">Push all linked policies</button></form>
            @endif
        </div>
    </div>
    <x-push-status :status="$pushStatus ?? null" />

    @if($state['error'])
        <div class="alert error"><strong>Live controller data is unavailable.</strong> {{ $state['error'] }} @if($isAdmin)<a href="{{ route('admin.settings.show', 'network') }}">Network provider settings</a>@endif</div>
    @elseif($state['fetched_at'])
        <p class="hint">Read from the controller {{ \Illuminate\Support\Carbon::parse($state['fetched_at'])->diffForHumans() }}. Mapping and linking only change this app; pushing changes only the allow and block lists of linked filters.</p>
    @endif

    @if($filters->isNotEmpty())
        <section class="policy-section">
            <div class="section-heading"><h2>Controller content filters</h2><p>Link each one to a filter policy so its lists can be fetched and reconciled automatically.</p></div>
            <div class="filter-cards">
                @foreach($filters as $filter)
                    <article class="panel filter-card">
                        <div class="policy-card-top"><span><strong>{{ $filter['name'] }}</strong><small>{{ count($filter['allow']) }} allowed · {{ count($filter['block']) }} blocked · {{ count($filter['categories']) }} categories</small></span>
                            @if(!$filter['enabled'])<span class="status status-disabled">Disabled</span>@elseif($filter['policy'])<x-sync-status :policy="$filter['policy']" />@else<span class="status status-pending">Not linked</span>@endif</div>
                        <p class="hint">Applied to: {{ $filter['network_names'] ? implode(', ', $filter['network_names']) : 'no networks' }}</p>
                        @php($overlaps = $networks->filter(fn ($network) => in_array($network['id'], $filter['network_ids'], true) && in_array($network['status'], ['multiple_filters', 'unmapped'], true) && count($network['attached']) > 1))
                        @if($overlaps->isNotEmpty())<p class="network-status warn">Shares {{ $overlaps->pluck('name')->join(', ') }} with {{ $overlaps->flatMap(fn ($network) => collect($network['attached'])->pluck('name'))->unique()->reject(fn ($name) => $name === $filter['name'])->join(', ') }}. Clients there get both filters.</p>@endif
                        @if($filter['policy']?->isLayer())
                            <p>Layer of <a href="{{ route('admin.policy.policies.show', $filter['policy']->layerOf) }}"><strong>{{ $filter['policy']->layerOf->name }}</strong></a>: carries its blocklist feeds. <a href="{{ route('admin.policy.policies.show', $filter['policy']) }}">Open the layer</a></p>
                        @elseif($filter['policy'])
                            <p>Managed by <a href="{{ route('admin.policy.policies.show', $filter['policy']) }}"><strong>{{ $filter['policy']->name }}</strong></a> · {{ $filter['policy']->typeLabel() }} filtering</p>
                            @if($filter['policy']->sync_checked_at)<p class="hint">{{ $filter['policy']->sync_details['summary'] ?? '' }} Checked {{ $filter['policy']->sync_checked_at->diffForHumans() }}.</p>@endif
                        @endif
                        @if($isAdmin && !$filter['policy']?->isLayer())
                            <form method="post" action="{{ route('admin.policy.networks.link') }}">@csrf
                                <input type="hidden" name="filter_id" value="{{ $filter['id'] }}">
                                <label>{{ $filter['policy'] ? 'Link to a different policy' : 'Link to policy' }}
                                    <select name="filter_policy_id">
                                        <option value="">Create a new policy…</option>
                                        @foreach($policies as $policy)<option value="{{ $policy->id }}" @selected($filter['policy']?->is($policy))>{{ $policy->name }} ({{ $policy->typeLabel() }})</option>@endforeach
                                    </select>
                                </label>
                                <label>New policy's network type <span class="optional">only when creating</span>
                                    <select name="audience">@foreach(\App\Models\NetworkType::options() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                                </label>
                                <button class="button small secondary">Save link and fetch lists</button>
                            </form>
                        @endif
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    @if($unfiltered->isNotEmpty() || !$state['error'])
        <section class="panel policy-section baseline" id="baseline">
            <div class="panel-heading"><div><h2>Baseline filtering</h2><p>No network is left without a filter by accident. A network with no content filter in UniFi is added to <strong>{{ $baselinePolicy?->name ?? 'the Students policy' }}</strong>'s filter, so it gets at least that level of filtering. To keep one unfiltered (a server or management network, say), allow it and say why.</p></div>
                @if($isAdmin && $unfiltered->where('state', 'enforce')->isNotEmpty())<form method="post" action="{{ route('admin.policy.networks.baseline') }}">@csrf<button class="button primary" data-confirm="Add every unfiltered network that is not allowed to {{ $baselinePolicy?->name ?? 'the Students policy' }}'s filter in UniFi?">Filter them now</button></form>@endif
            </div>
            @if(!$baselinePolicy)
                <p class="network-status warn">No Students policy is linked to a UniFi content filter yet, so there is nothing to add unfiltered networks to. Link one in <a href="{{ route('admin.policy.setup.show') }}">Guided setup</a>.</p>
            @endif
            @if($unfiltered->isEmpty())
                <p class="network-status ok">Every network has a content filter.</p>
            @else
                <div class="baseline-rows">
                    @foreach($unfiltered as $item)
                        @php($network = $item['network'])
                        <div class="baseline-row">
                            <span><strong>{{ $network->name }}</strong><small>{{ $network->vlan ? 'VLAN '.$network->vlan : 'Untagged' }}{{ $network->subnet ? ' · '.$network->subnet : '' }}</small></span>
                            <span>
                                @switch($item['state'])
                                    @case('allowed')<span class="network-status muted">Allowed unfiltered: {{ $network->unfiltered_reason }}</span><small>{{ $network->unfilteredReviewedBy?->name ?? 'An admin' }}, {{ $network->unfiltered_reviewed_at->local()->format('j M Y') }}</small>@break
                                    @case('paused')<span class="network-status bad">Unfiltered: {{ implode(', ', $item['paused']) }} {{ count($item['paused']) === 1 ? 'is' : 'are' }} paused in UniFi</span><small>Turn it back on in UniFi, filter it now, or allow it unfiltered for now.</small>@break
                                    @case('decide')<span class="network-status warn">Needs a decision: it had no filter when baseline filtering started</span>@break
                                    @default<span class="network-status bad">Gets {{ $baselinePolicy?->name ?? 'Students' }} filtering at the next check (every 15 minutes, Active mode)</span>
                                @endswitch
                            </span>
                            @if($isAdmin)
                                <span class="button-row">
                                    @if($item['state'] !== 'allowed' && $baselinePolicy)
                                        <form method="post" action="{{ route('admin.policy.networks.baseline') }}">@csrf<input type="hidden" name="networks[]" value="{{ $network->provider_network_id }}"><button class="button small primary" data-confirm="Add {{ $network->name }} to {{ $baselinePolicy->name }}'s filter in UniFi?">Filter it now</button></form>
                                    @endif
                                    @if($item['state'] === 'allowed')
                                        <form method="post" action="{{ route('admin.policy.networks.unfiltered', $network) }}">@csrf @method('PUT')<input type="hidden" name="allow" value="0"><button class="button small secondary">Stop allowing</button></form>
                                    @else
                                        <details class="inline-allow"><summary class="button small secondary">Allow unfiltered…</summary>
                                            <form method="post" action="{{ route('admin.policy.networks.unfiltered', $network) }}">@csrf @method('PUT')<input type="hidden" name="allow" value="1">
                                                <label>Why it stays unfiltered<input name="reason" required maxlength="255" placeholder="Servers and switches; no users"></label>
                                                <button class="button small primary">Allow</button>
                                            </form>
                                        </details>
                                    @endif
                                </span>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
            @if($isAdmin && $policies->filter(fn ($policy) => filled($policy->provider_reference))->count() > 1)
                <form method="post" action="{{ route('admin.policy.networks.baseline-policy') }}" class="search-line">@csrf @method('PUT')
                    <label>Baseline policy<select name="policy_id">@foreach($policies->filter(fn ($policy) => filled($policy->provider_reference)) as $policy)<option value="{{ $policy->id }}" @selected($baselinePolicy?->is($policy))>{{ $policy->name }} · {{ $policy->typeLabel() }}</option>@endforeach</select></label>
                    <button class="button secondary">Use this policy</button>
                </form>
            @endif
        </section>
    @endif

    <section class="panel table-wrap policy-section">
        <div class="panel-heading"><div><h2>Networks (VLANs)</h2><p>Choose the filter policy each network should have. Its network type sets the filtering level: it inherits that type's lists plus every site-wide list.</p></div></div>
        @if($networks->isEmpty() && $stored->isEmpty())
            <p class="hint">No networks are known yet. Link a network provider that can list networks, then refresh.</p>
        @else
            <form method="post" action="{{ route('admin.policy.networks.update') }}">@csrf @method('PUT')
                <table>
                    <thead><tr><th>Network</th><th>VLAN</th><th>Filter in controller</th><th>Policy here</th><th>Status</th></tr></thead>
                    <tbody>
                        @foreach($networks->isNotEmpty() ? $networks : $stored->map(fn ($row) => ['id' => $row->provider_network_id, 'name' => $row->name, 'vlan' => $row->vlan, 'purpose' => $row->purpose, 'policy' => $row->filterPolicy, 'attached' => null, 'status' => 'unknown']) as $network)
                            <tr>
                                <td><strong>{{ $network['name'] }}</strong><small>{{ str($network['purpose'] ?? '')->replace('-', ' ')->title() }}</small></td>
                                <td>{{ $network['vlan'] ?? '—' }}</td>
                                <td>@if($network['attached'] === null)<span class="hint">Unknown</span>@elseif($network['attached'] === [])<span class="hint">None</span>@else{{ collect($network['attached'])->pluck('name')->join(', ') }}@if(count($network['attached']) > 1 && !$network['policy'])<small class="network-status warn">Multiple filters</small>@endif @endif</td>
                                <td>
                                    @if($isAdmin)
                                        <select name="assignments[{{ $network['id'] }}]" aria-label="Policy for {{ $network['name'] }}">
                                            <option value="">Not managed</option>
                                            @foreach($policies as $policy)<option value="{{ $policy->id }}" @selected($network['policy']?->is($policy))>{{ $policy->name }} · {{ $policy->typeLabel() }}</option>@endforeach
                                        </select>
                                    @else
                                        {{ $network['policy'] ? $network['policy']->name.' · '.$network['policy']->typeLabel() : 'Not managed' }}
                                    @endif
                                </td>
                                <td>
                                    @switch($network['status'])
                                        @case('ok')@if(collect($network['attached'])->doesntContain(fn ($filter) => $filter['enabled'] ?? true))<span class="network-status bad">Matches, but the filter is paused in UniFi: unfiltered</span>@else<span class="network-status ok">Matches{{ $network['layer'] ? ' · with layer '.($network['layer']->provider_name ?: $network['layer']->name) : '' }}</span>@endif @break
                                        @case('layer_missing')<span class="network-status warn">Add the layer {{ $network['layer']->provider_name ?: $network['layer']->name }} to this network in the controller, or its feeds are not blocked here</span>@break
                                        @case('multiple_filters')<span class="network-status warn">In {{ count($network['attached']) }} filters: a hostname allowed by {{ $network['policy']->provider_name ?: $network['policy']->name }} can still be blocked by {{ collect($network['others'])->pluck('name')->join(', ') }}</span>@break
                                        @case('unfiltered')@php($baselineItem = $unfiltered->first(fn ($item) => $item['network']->provider_network_id === $network['id']))<span class="network-status {{ ($baselineItem['state'] ?? '') === 'allowed' ? 'muted' : 'bad' }}">{{ ($baselineItem['state'] ?? '') === 'allowed' ? 'Unfiltered (allowed)' : 'Unfiltered: see Baseline filtering' }}</span>@break
                                        @case('unmapped')<span class="network-status warn">Filtered in controller, not managed here</span>@break
                                        @case('policy_unlinked')<span class="network-status warn">Link {{ $network['policy']->name }} to a controller filter</span>@break
                                        @case('missing_filter')<span class="network-status warn">Attach {{ $network['policy']->provider_name ?: $network['policy']->name }} to this network in the controller</span>@break
                                        @case('wrong_filter')<span class="network-status warn">Controller uses a different filter; expected {{ $network['policy']->provider_name ?: $network['policy']->name }}</span>@break
                                        @default<span class="network-status muted">Last known</span>
                                    @endswitch
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if($isAdmin)<div class="form-actions"><button class="button primary">Save network mapping</button></div>@endif
            </form>
        @endif
    </section>
</x-layouts.app>
