<x-layouts.app title="Networks & filters">
    <x-slot:head><link rel="stylesheet" href="{{ asset('admin.css') }}"></x-slot:head>
    @php
        $isAdmin = auth()->user()->isAdmin();
        $baselineName = $baselineLabel ?? $baselinePolicy?->name ?? 'Students';
        $baselineFilter = $baselinePolicy ? collect($state['filters'])->firstWhere('id', $baselinePolicy->provider_reference) : null;
        $baselineWhat = $baselineFilter ? 'the UniFi filter '.$baselineFilter['name'].' ('.($baselineFilter['schedule_label'] ?? \App\Services\Network\FilterSchedule::label($baselineFilter['schedule'] ?? null)).'; '.count($baselineFilter['categories'] ?? []).' blocked categories'.(! empty($baselineFilter['safe_search']) ? ', SafeSearch' : '').')' : $baselineName;
        $addedBy = collect($automatic)->flatMap(fn ($item) => $item['networks']->map(fn ($network) => [$network->provider_network_id.'|'.$item['filter_id'], $item]))->mapWithKeys(fn ($pair) => [$pair[0] => $pair[1]]);
        $roleLabel = ['sitewide' => 'site-wide', 'layer' => 'blocklist layer', 'hours' => 'school hours', 'group' => null];
        $writable = $canPush;
    @endphp

    <div class="page-heading">
        <div>
            <p class="eyebrow">Web filtering</p>
            <h1>Networks &amp; filters</h1>
            <p>Every VLAN, the UniFi filters on it, and whether that's right. Fix things here and the row updates in place.</p>
        </div>
        <div class="button-row">
            @if($isAdmin && $available)<a class="button secondary" href="{{ route('admin.policy.setup.show') }}">Guided setup</a>@endif
            <a class="button secondary" href="{{ route('admin.policy.networks.index', ['refresh' => 1]) }}">Read UniFi again</a>
            @if($available)
                <form method="post" action="{{ route('admin.policy.networks.check') }}">@csrf<button class="button secondary">Check sync now</button></form>
            @endif
            @if($canPush)
                <form method="post" action="{{ route('admin.policy.networks.push') }}">@csrf<button class="button primary" data-confirm="Push pending changes to every linked content filter?">Push all linked policies</button></form>
            @endif
        </div>
    </div>
    <x-push-status :status="$pushStatus ?? null" />

    @if($state['error'])
        <div class="alert error"><strong>UniFi could not be read.</strong> {{ $state['error'] }} @if($isAdmin)<a href="{{ route('admin.settings.show', 'network') }}">Network provider settings</a>@endif</div>
    @endif

    <div id="networks-live">
        <div data-flash-slot></div>
        @if($isAdmin && ($confirm = session('link_confirm')))
            <div class="alert error link-confirm">
                <strong>Move the link? Nothing has changed yet.</strong>
                <p>Linking {{ $confirm['filter'] }} to {{ $confirm['policy'] }} means:</p>
                <ul>@foreach($confirm['moves'] as $move)<li>{{ $move }}.</li>@endforeach
                    <li>{{ $confirm['filter'] }}'s current entries ({{ $confirm['counts'] }}) are kept: they are copied into {{ $confirm['policy'] }}'s own lists, so nothing is removed from the filter.</li>
                    <li>{{ $confirm['policy'] }}'s lists are then added to {{ $confirm['filter'] }}, on its networks. Nothing is pushed automatically until someone checks the plan and pushes from the policy page.</li>
                </ul>
                <form method="post" action="{{ route('admin.policy.networks.link') }}" class="button-row">@csrf
                    <input type="hidden" name="filter_id" value="{{ $confirm['filter_id'] }}"><input type="hidden" name="managed_by" value="{{ $confirm['managed_by'] }}"><input type="hidden" name="confirmed" value="1">
                    <button class="button primary">Move the link</button>
                    <a class="button secondary" href="{{ route('admin.policy.networks.index') }}">Cancel</a>
                </form>
            </div>
        @endif
        @if($isAdmin && $automatic->isNotEmpty())
            @foreach($automatic as $item)
                <div class="notice baseline-added">
                    <div><strong>Filterdesk added {{ $item['filter_name'] }} to {{ $item['networks']->count() }} {{ $item['networks']->count() === 1 ? 'network' : 'networks' }}</strong> on {{ $item['event']->created_at->local()->format('j M Y g:i a') }}, {{ $item['by'] }}, for baseline filtering.
                        <x-chips :items="$item['networks']->pluck('name')->all()" :max="6" /></div>
                    @if($writable)
                        <form method="post" action="{{ route('admin.policy.networks.baseline-undo', $item['event']) }}" data-async="#networks-live" data-confirm-inline="Take {{ $item['filter_name'] }} off {{ $item['networks']->count() }} network(s)?">@csrf<button class="button small secondary">Take it off again</button></form>
                    @endif
                    <small>Taking it off lists them under <em>No filter</em> to decide one by one; Filterdesk won't add it back by itself.</small>
                </div>
            @endforeach
        @endif

        @if($rows !== [])
            @php
                $counts = ['all' => count($rows), 'attention' => collect($rows)->where('attention', true)->count(), 'user' => collect($rows)->where('kind', 'user')->count(),
                    'device' => collect($rows)->where('kind', 'device')->count(), 'unfiltered' => collect($rows)->where('unfiltered', true)->count()];
            @endphp
            <section class="panel policy-section">
                <div class="panel-heading">
                    <div>
                        <h2>Networks</h2>
                        <p>How it works: each network gets the filters attached to it in UniFi. <strong>Group</strong> filters are for one type of user (Staff, Students, Guests); <strong>site-wide</strong> filters are on every network. A new <em>user</em> network with no filter gets {{ $baselineName }} at the next check (Active mode only). Device networks, networks found at setup or while read-only, and networks whose filters are paused are never changed by themselves: they wait for you. Device networks don't need a group filter, but adding one protects them if a student gets on.</p>
                    </div>
                </div>
                <div class="row-tabs" role="tablist" data-row-tabs="#network-rows">
                    @foreach(['attention' => 'Needs attention', 'all' => 'All', 'user' => 'User networks', 'device' => 'Device networks', 'unfiltered' => 'No filter'] as $key => $label)
                        <button type="button" role="tab" data-tab="{{ $key }}" @class(['tab', 'active' => $key === ($counts['attention'] ? 'attention' : 'all')])>{{ $label }} <span class="count">{{ $counts[$key] }}</span></button>
                    @endforeach
                </div>
                @if(!$writable)<p class="hint">Read-only mode: adding or removing filters on a network needs the network provider in Active mode (Settings → Network provider). The rest of this page still works.</p>@endif

                <div class="table-wrap net-table"><table id="network-rows">
                    <thead><tr><th>Network</th><th>Kind</th><th>Filters in UniFi</th><th>Policy here</th><th>Status</th></tr></thead>
                    <tbody>
                        @foreach($rows as $row)
                            <tr data-kind="{{ $row['kind'] }}" data-attention="{{ $row['attention'] ? 1 : 0 }}" data-unfiltered="{{ $row['unfiltered'] ? 1 : 0 }}">
                                <td><strong>{{ $row['name'] }}</strong><small>{{ $row['vlan'] ? 'VLAN '.$row['vlan'] : 'Untagged' }}{{ $row['subnet'] ? ' · '.$row['subnet'] : '' }}</small></td>
                                <td>
                                    @if($isAdmin && $row['assignment'])
                                        <form method="post" action="{{ route('admin.policy.networks.kind', $row['assignment']) }}" data-async="#networks-live">@csrf @method('PUT')
                                            <select name="kind" data-autosubmit aria-label="Kind of network for {{ $row['name'] }}">
                                                <option value="user" @selected($row['kind'] === 'user')>User</option>
                                                <option value="device" @selected($row['kind'] === 'device')>Device</option>
                                                @if($row['kind_confirmed'])<option value="auto">Learn again</option>@endif
                                            </select>
                                            @unless($row['kind_confirmed'])<small>learned</small>@endunless
                                        </form>
                                    @else
                                        {{ ucfirst($row['kind']) }}
                                    @endif
                                </td>
                                <td>
                                    <span class="chips">
                                        @forelse($row['filters'] as $filter)
                                            <span @class(['chip', 'chip-filter', 'chip-'.$filter['role'], 'chip-paused' => $filter['paused']]) title="{{ $filter['paused'] ? 'Paused in UniFi' : ($roleLabel[$filter['role']] ?? 'group').($filter['when'] ? ' · '.$filter['when'] : '') }}">
                                                {{ $filter['name'] }}@if($roleLabel[$filter['role']] ?? null)<em>{{ $roleLabel[$filter['role']] }}</em>@endif
                                                @if($added = $addedBy->get($row['id'].'|'.$filter['id']))<em class="added-by" title="Added {{ $added['by'] }} for baseline filtering">added {{ $added['event']->created_at->local()->format('j M') }}</em>@endif
                                                @if($isAdmin && $writable)
                                                    <form method="post" action="{{ route('admin.policy.networks.filter', $row['assignment']) }}" data-async="#networks-live" data-confirm-inline="Remove from {{ $row['name'] }}?">@csrf<input type="hidden" name="filter_id" value="{{ $filter['id'] }}"><input type="hidden" name="remove" value="1"><button class="chip-remove" aria-label="Remove {{ $filter['name'] }} from {{ $row['name'] }}">×</button></form>
                                                @endif
                                            </span>
                                        @empty
                                            <span class="hint">None</span>
                                        @endforelse
                                    </span>
                                    @if($isAdmin && $writable && $row['assignment'])
                                        @php($addable = collect($state['filters'])->reject(fn ($filter) => collect($row['filters'])->pluck('id')->contains($filter['id'])))
                                        @if($addable->isNotEmpty())
                                            <details class="add-filter"><summary>+ Add filter</summary>
                                                <form method="post" action="{{ route('admin.policy.networks.filter', $row['assignment']) }}" data-async="#networks-live">@csrf
                                                    <select name="filter_id" aria-label="Filter to add to {{ $row['name'] }}">@foreach($addable as $filter)<option value="{{ $filter['id'] }}">{{ $filter['name'] }}{{ ($filter['enabled'] ?? true) ? '' : ' (paused)' }}</option>@endforeach</select>
                                                    <button class="button small primary">Add</button>
                                                </form>
                                            </details>
                                        @endif
                                    @endif
                                </td>
                                <td>
                                    @if($isAdmin && $row['assignment'])
                                        <form method="post" action="{{ route('admin.policy.networks.update') }}" data-async="#networks-live">@csrf @method('PUT')
                                            <select name="assignments[{{ $row['id'] }}]" data-autosubmit aria-label="Policy for {{ $row['name'] }}">
                                                <option value="">Not managed</option>
                                                @foreach($policies->reject(fn ($policy) => $policy->isSitewide()) as $policy)<option value="{{ $policy->id }}" @selected($row['policy']?->is($policy))>{{ $policy->name }}</option>@endforeach
                                            </select>
                                        </form>
                                    @else
                                        {{ $row['policy']?->name ?? 'Not managed' }}
                                    @endif
                                </td>
                                <td>
                                    <span class="network-status {{ $row['status']['level'] }}">{{ $row['status']['text'] }}</span>
                                    @if($isAdmin && $row['status']['actions'])
                                        <span class="row-fixes">
                                            @foreach($row['status']['actions'] as $action)
                                                @if($action === 'protect' && $baselinePolicy && $writable)
                                                    <form method="post" action="{{ route('admin.policy.networks.protect') }}" data-async="#networks-live" data-confirm-inline="Add to {{ $row['name'] }}?" data-confirm-detail="Adds {{ $baselineWhat }} to {{ $row['name'] }} in UniFi.{{ $row['kind'] === 'device' ? ' This is a device network (infrastructure, cameras, uplinks…): it only needs this if people\'s devices can get onto it.' : '' }} Undo from the top of this page.">@csrf<input type="hidden" name="networks[]" value="{{ $row['id'] }}"><button class="button small secondary">Add {{ $baselineName }}</button></form>
                                                @elseif($action === 'add_filter' && ($row['status']['filter_id'] ?? null) && $writable && $row['assignment'])
                                                    <form method="post" action="{{ route('admin.policy.networks.filter', $row['assignment']) }}" data-async="#networks-live" data-confirm-inline="Add {{ $row['status']['filter_name'] }} to {{ $row['name'] }}?">@csrf<input type="hidden" name="filter_id" value="{{ $row['status']['filter_id'] }}"><button class="button small secondary">Add {{ $row['status']['filter_name'] }}</button></form>
                                                @elseif($action === 'remap' && ($row['status']['policy'] ?? null))
                                                    <form method="post" action="{{ route('admin.policy.networks.update') }}" data-async="#networks-live">@csrf @method('PUT')<input type="hidden" name="assignments[{{ $row['id'] }}]" value="{{ $row['status']['policy']->id }}"><button class="button small secondary">Map to {{ $row['status']['policy']->name }}</button></form>
                                                @elseif($action === 'follow')
                                                    <form method="post" action="{{ route('admin.policy.networks.follow') }}" data-async="#networks-live">@csrf<input type="hidden" name="networks[]" value="{{ $row['id'] }}"><button class="button small secondary">Follow UniFi</button></form>
                                                @elseif($action === 'allow' && $row['assignment'])
                                                    <details class="inline-allow"><summary class="button small secondary">Allow unfiltered…</summary>
                                                        <form method="post" action="{{ route('admin.policy.networks.unfiltered', $row['assignment']) }}" data-async="#networks-live">@csrf @method('PUT')<input type="hidden" name="allow" value="1">
                                                            <label>Why<input name="reason" required maxlength="255" placeholder="Switches and servers only"></label>
                                                            <button class="button small primary">Allow</button>
                                                        </form>
                                                    </details>
                                                @elseif($action === 'stop_allowing' && $row['assignment'])
                                                    <form method="post" action="{{ route('admin.policy.networks.unfiltered', $row['assignment']) }}" data-async="#networks-live">@csrf @method('PUT')<input type="hidden" name="allow" value="0"><button class="link-button">Stop allowing</button></form>
                                                @endif
                                            @endforeach
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
                <p class="hint empty-tab" hidden>Nothing here.</p>
            </section>
        @elseif($stored->isNotEmpty())
            <section class="panel table-wrap policy-section">
                <h2>Networks (last known)</h2>
                <table><thead><tr><th>Network</th><th>VLAN</th><th>Policy here</th></tr></thead><tbody>
                    @foreach($stored as $network)<tr><td>{{ $network->name }}</td><td>{{ $network->vlan ?? '—' }}</td><td>{{ $network->filterPolicy?->name ?? 'Not managed' }}</td></tr>@endforeach
                </tbody></table>
            </section>
        @endif

        @if($filters->isNotEmpty())
            <section class="panel policy-section">
                <div class="panel-heading"><div>
                    <h2>Content filters</h2>
                    <p><strong>Managed by</strong> chooses which Filterdesk policy keeps each UniFi filter's allow and block lists. Pick <em>an existing policy</em> to keep its lists and history (for example the default Students policy). Pick <em>New policy for…</em> to create one: its type (Staff, Students, Guests…) decides which shared lists it gets. A <em>site-wide layer</em> is a filter on every network, carrying the lists for everyone. A <em>school-hours layer</em> is a second filter for the same type that UniFi switches on by its schedule (for example <em>Student - School Time</em> beside <em>Students - All Hours</em>): it keeps its own allows and blocks, and the policy's allows go into it too, so an approval works at every hour.</p>
                </div></div>
                <div class="table-wrap filter-table"><table>
                    <thead><tr><th>Filter</th><th>Networks</th><th>Managed by</th><th>Sync</th></tr></thead>
                    <tbody>
                        @foreach($filters as $filter)
                            <tr @class(['paused' => !($filter['enabled'] ?? true)])>
                                <td>@if($filter['policy'])<a href="{{ route('admin.policy.policies.show', $filter['policy']) }}"><strong>{{ $filter['name'] }}</strong></a>@else<strong>{{ $filter['name'] }}</strong>@endif
                                    <small>{{ number_format(count($filter['allow'])) }} allowed · {{ number_format(count($filter['block'])) }} blocked{{ ($filter['enabled'] ?? true) ? '' : ' · paused in UniFi' }}</small>
                                    @php($scheduled = ($filter['schedule'] ?? 'ALWAYS') !== 'ALWAYS')
                                    @if($scheduled)<small class="schedule-when">On a schedule: {{ $filter['schedule_label'] ?? \App\Services\Network\FilterSchedule::label($filter['schedule']) }}</small>@endif</td>
                                <td><x-chips :items="$filter['network_names']" :max="2" /></td>
                                <td>
                                    @if($filter['policy']?->isLayer() && ! $filter['policy']->isHoursLayer())
                                        Blocklist layer of <a href="{{ route('admin.policy.policies.show', $filter['policy']->layerOf) }}">{{ $filter['policy']->layerOf->name }}</a>
                                    @elseif($isAdmin)
                                        <form method="post" action="{{ route('admin.policy.networks.link') }}" data-async="#networks-live">@csrf
                                            <input type="hidden" name="filter_id" value="{{ $filter['id'] }}">
                                            <select name="managed_by" data-autosubmit aria-label="Managed by, for {{ $filter['name'] }}">
                                                @unless($filter['policy'])<option value="" selected disabled>Not managed: choose…</option>@endunless
                                                @if($scheduled || $filter['policy']?->isHoursLayer())
                                                    <optgroup label="School-hours layer (keeps its allows, blocks and schedule)">
                                                        @foreach($policies->reject(fn ($policy) => $policy->isSitewide() || $policy->provider_reference === $filter['id']) as $policy)<option value="hours:{{ $policy->id }}" @selected($filter['policy']?->isHoursLayer() && $filter['policy']->layer_of_id === $policy->id)>School-hours layer of {{ $policy->name }}</option>@endforeach
                                                    </optgroup>
                                                @endif
                                                <optgroup label="An existing policy">
                                                    @foreach($policies as $policy)<option value="policy:{{ $policy->id }}" @selected($filter['policy']?->is($policy))>{{ $policy->name }} · {{ $policy->isSitewide() ? 'site-wide layer' : $policy->typeLabel() }}{{ filled($policy->provider_reference) && $policy->provider_reference !== $filter['id'] ? ' (linked to '.$policy->provider_name.': asks before moving)' : '' }}</option>@endforeach
                                                </optgroup>
                                                <optgroup label="A new policy">
                                                    @foreach(\App\Models\NetworkType::options() as $value => $label)<option value="new:{{ $value }}">New policy for {{ $label }}</option>@endforeach
                                                    <option value="sitewide">New site-wide layer</option>
                                                </optgroup>
                                            </select>
                                        </form>
                                    @else
                                        {{ $filter['policy']?->name ?? 'Not managed' }}
                                    @endif
                                </td>
                                <td>@if($filter['policy'])<x-sync-status :policy="$filter['policy']" />@else<span class="status status-pending">Not linked</span>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
            </section>
        @endif

        @if($isAdmin && $policies->filter(fn ($policy) => filled($policy->provider_reference) && $policy->audience === 'student' && ! $policy->isSitewide())->count() > 1)
            <details class="panel policy-section">
                <summary>Which Students filter protects networks without one</summary>
                <p class="hint">Now: <strong>{{ $baselineName }}</strong>. An always-on filter is best: one on a school-hours schedule filters nothing out of hours.</p>
                <form method="post" action="{{ route('admin.policy.networks.baseline-policy') }}" class="search-line" data-async="#networks-live">@csrf @method('PUT')
                    <label>Baseline filter<select name="policy_id">@foreach($policies->filter(fn ($policy) => filled($policy->provider_reference) && ! $policy->isSitewide()) as $policy)<option value="{{ $policy->id }}" @selected($baselinePolicy?->is($policy))>{{ $policy->name }} ({{ $policy->provider_name }})</option>@endforeach</select></label>
                    <button class="button secondary">Use this</button>
                </form>
            </details>
        @endif
    </div>
</x-layouts.app>
