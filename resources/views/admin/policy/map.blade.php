<x-layouts.app title="Filtering map">
    <x-slot:head><link rel="stylesheet" href="{{ asset('admin.css') }}"></x-slot:head>

    <div class="page-heading">
        <div>
            <p class="eyebrow">Web filtering</p>
            <h1>Filtering map</h1>
            <p>Pick a network to see the whole chain: every content filter UniFi puts on it, the Filterdesk policy behind each one, and the lists that fill them.</p>
        </div>
        <a class="button secondary" href="{{ route('admin.policy.map', ['refresh' => 1, 'network' => $selected['id'] ?? null]) }}">Read UniFi again</a>
    </div>

    @if($error)
        <div class="alert error"><strong>UniFi could not be read.</strong> {{ $error }}</div>
    @else
        <div class="notice map-legend">
            <strong>How it fits together.</strong> A network in UniFi can have several content filters. UniFi applies all of them: a site blocked by any one is blocked, and an allow in one filter does not lift a block in another. Each filter is managed by one Filterdesk policy, and a policy's lists come from three levels: site-wide lists (every network), the network type's lists (Students, Staff…), and the policy's own lists. {{ $protectedCount }} protected site(s) are never blocked (<a href="{{ route('admin.settings.show', 'protected') }}">Settings → Protected sites</a>).
        </div>

        <div class="map-layout">
            <nav class="panel map-networks" aria-label="Networks">
                <h2>Networks</h2>
                @foreach($networks as $network)
                    <a href="{{ route('admin.policy.map', ['network' => $network['id']]) }}" @class(['map-network', 'active' => ($selected['id'] ?? null) === $network['id']])>
                        <strong>{{ $network['name'] }}@if($network['vlan']) <small>VLAN {{ $network['vlan'] }}</small>@endif</strong>
                        <small>{{ $network['type'] ?? 'No type' }} · {{ count($network['attached']) }} filter(s)</small>
                        <span class="status status-{{ in_array($network['status'], ['ok', 'hours_only'], true) ? 'ok' : (in_array($network['status'], ['unfiltered', 'unmapped'], true) ? 'expired' : 'pending') }}">{{ $network['summary'] }}</span>
                    </a>
                @endforeach
            </nav>

            @if($selected)
                <div class="map-chain">
                    <section class="panel map-step">
                        <p class="eyebrow">1 · Network</p>
                        <h2>{{ $selected['name'] }}@if($selected['vlan']) · VLAN {{ $selected['vlan'] }}@endif</h2>
                        <p>{{ $selected['subnet'] ?? '' }} @if($selected['policy'])Filterdesk treats it as <strong>{{ $selected['type'] }}</strong>, managed by <a href="{{ route('admin.policy.policies.show', $selected['policy']) }}">{{ $selected['policy']->name }}</a>.@else Not mapped to a network type in Filterdesk.@endif</p>
                        <p class="hint">{{ $selected['summary'] }}. Change the mapping on <a href="{{ route('admin.policy.networks.index') }}">Networks &amp; filters</a>.</p>
                        @foreach($trace['missing'] as $missing)
                            <div class="alert error">Expected but not on this network in UniFi: {{ $missing }}.</div>
                        @endforeach
                    </section>

                    <p class="map-arrow" aria-hidden="true">↓ UniFi applies {{ count($trace['filters']) === 1 ? 'this filter' : 'all '.count($trace['filters']).' filters' }}</p>

                    @forelse($trace['filters'] as $row)
                        <section @class(['panel', 'map-step', 'map-unexpected' => ! $row['expected'] && $row['policy']?->isSitewide() !== true])>
                            <p class="eyebrow">2 · Content filter in UniFi</p>
                            <h2>{{ $row['filter']['name'] }} @if(! ($row['filter']['enabled'] ?? true))<span class="status status-expired">Paused in UniFi</span>@endif</h2>
                            <p>{{ $row['role'] }}.@if(($row['filter']['schedule'] ?? 'ALWAYS') !== 'ALWAYS') On a schedule: {{ $row['filter']['schedule_label'] ?? '' }}.@endif</p>
                            <p class="hint">In UniFi now: {{ number_format(count($row['filter']['allow'])) }} allowed · {{ number_format(count($row['filter']['block'])) }} blocked · {{ count($row['filter']['categories']) }} categories blocked</p>
                            @if($row['filter']['categories'])
                                <details><summary>Blocked categories</summary><x-chips :items="collect($row['filter']['categories'])->map(fn ($category) => str($category)->replace('_', ' ')->lower()->ucfirst()->toString())->all()" :max="60" /></details>
                            @endif

                            @if($row['policy'])
                                <div class="map-substep">
                                    <p class="eyebrow">3 · Filterdesk policy</p>
                                    <h3><a href="{{ route('admin.policy.policies.show', $row['policy']) }}">{{ $row['policy']->name }}</a> <x-sync-status :policy="$row['policy']" />@if(filled($row['policy']->push_hold))<span class="status status-pending">On hold</span>@endif</h3>
                                    @if($row['desired'])
                                        <p class="hint">Filterdesk wants: {{ number_format($row['desired']['allow']) }} allowed · {{ number_format($row['desired']['block']) }} blocked{{ $row['desired']['protected'] ? ' · '.$row['desired']['protected'].' protected site(s) left out of the blocks' : '' }}</p>
                                    @endif
                                    <p class="eyebrow">4 · Lists that fill it</p>
                                    <div class="table-wrap compact"><table>
                                        <thead><tr><th>List</th><th>Level</th><th>Kind</th><th>Entries</th></tr></thead>
                                        <tbody>
                                            @foreach($row['lists'] as $list)
                                                <tr>
                                                    <td><a href="{{ route('admin.policy.lists.show', $list) }}">{{ $list->name }}</a>@if($list->feed)<small>Blocklist feed</small>@endif</td>
                                                    <td>{{ match ($list->scope->value) { 'site' => 'Site-wide', 'audience' => \App\Models\NetworkType::labelFor($list->audience), default => 'This policy' } }}</td>
                                                    <td><span class="status status-{{ $list->kind->value === 'allow' ? 'ok' : 'rejected' }}">{{ ucfirst($list->kind->value) }}</span></td>
                                                    <td>{{ number_format($list->entries_count) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table></div>
                                </div>
                            @else
                                <p class="hint">Filterdesk does not manage this filter, so its lists are only in UniFi. Link it on <a href="{{ route('admin.policy.networks.index') }}">Networks &amp; filters</a> to manage it here.</p>
                            @endif
                        </section>
                    @empty
                        <section class="panel map-step"><p>No content filter is on this network in UniFi, so it is not filtered.</p></section>
                    @endforelse

                    <p class="hint">To see why one site is allowed or blocked here, use the <a href="{{ route('admin.policy.explorer', ['type' => $selected['policy']?->audience]) }}">Access explorer</a>.</p>
                </div>
            @endif
        </div>
    @endif
</x-layouts.app>
