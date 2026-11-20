<x-layouts.app title="Set up from controller">
    <x-slot:head><link rel="stylesheet" href="{{ asset('admin.css') }}"></x-slot:head>

    <div class="page-heading">
        <div>
            <p class="eyebrow">Web filtering · guided setup</p>
            <h1>Set up from the controller</h1>
            <p>Bring each controller content filter in as a filter policy, map its networks, then add a probe per policy so website checks see what each filter does.</p>
        </div>
        <a class="button secondary" href="{{ route('admin.policy.setup.show', ['refresh' => 1]) }}">Refresh from controller</a>
    </div>

    @if($state['error'])
        <div class="alert error"><strong>The controller could not be read.</strong> {{ $state['error'] }} <a href="{{ route('admin.settings.show', 'network') }}">Network provider settings</a></div>
    @else
        <section class="panel policy-section setup-quick">
            <div class="panel-heading">
                <div><h2>Bring everything in</h2><p>Reads every content filter in UniFi and sets the tool up to match it in one step: each filter becomes a policy (the default Students and Staff policies are reused where the audience fits), a filter that looks like a blocklist layer on the same networks becomes that policy's layer, every allow and block entry is imported, networks are mapped and categories are kept. Nothing is changed in UniFi, and the first push changes nothing. Review the choices below first if you prefer.</p></div>
                <form method="post" action="{{ route('admin.policy.setup.import-all') }}">@csrf<input type="hidden" name="parent" value="{{ $parent }}">
                    <button class="button primary" data-confirm="Bring in all {{ $filters->count() }} content filters with the suggested settings? Nothing is changed in UniFi.">Bring everything in</button>
                </form>
            </div>
        </section>

        <section class="panel policy-section">
            <div class="panel-heading"><div><h2>1. Content filters</h2><p>{{ $filters->count() }} found. For each, link an existing policy or create one. The audience decides which shared lists (Staff, Student, Guest…) it inherits, and with them the level of filtering.</p></div></div>
            <form method="post" action="{{ route('admin.policy.setup.store') }}">@csrf
                <div class="table-wrap"><table>
                    <thead><tr><th>Controller filter</th><th>Networks</th><th>Set up as</th><th>Import entries</th></tr></thead>
                    <tbody>
                        @foreach($filters as $filter)
                            <tr>
                                <td><strong>{{ $filter['name'] }}</strong><small>{{ count($filter['allow']) }} allowed · {{ count($filter['block']) }} blocked · {{ count($filter['categories']) }} categories{{ $filter['enabled'] ? '' : ' · disabled' }}</small></td>
                                <td>
                                    @forelse($filter['networks'] as $network)<small>{{ $network['name'] }}{{ $network['vlan'] ? ' · VLAN '.$network['vlan'] : '' }}</small>@empty<small class="hint">No networks</small>@endforelse
                                    @foreach($filter['shared'] as $network)<small class="network-status warn">{{ $network['name'] }} is also in {{ collect($network['attached'])->reject(fn ($other) => $other['id'] === $filter['id'])->pluck('name')->join(', ') }}</small>@endforeach
                                </td>
                                <td>
                                    @php($suggestion = $filter['suggestion'])
                                    <select name="filters[{{ $filter['id'] }}][action]" aria-label="Action for {{ $filter['name'] }}">
                                        <option value="create" @selected($suggestion['action'] === 'create')>Create a new policy</option>
                                        <option value="link" @selected($suggestion['action'] === 'link')>Link to an existing policy</option>
                                        <option value="layer" @selected($suggestion['action'] === 'layer')>Layer of another filter (blocklist feeds)</option>
                                        <option value="skip">Skip</option>
                                    </select>
                                    <select name="filters[{{ $filter['id'] }}][policy_id]" aria-label="Existing policy for {{ $filter['name'] }}">
                                        @foreach($policies as $policy)<option value="{{ $policy->id }}" @selected($suggestion['policy_id'] === $policy->id)>{{ $policy->name }} ({{ $policy->audience->label() }}){{ filled($policy->provider_reference) && $policy->provider_reference !== $filter['id'] ? ' · linked to '.$policy->provider_name : '' }}</option>@endforeach
                                    </select>
                                    <select name="filters[{{ $filter['id'] }}][audience]" aria-label="Audience for a new policy">
                                        @foreach(\App\Enums\Audience::options() as $value => $label)<option value="{{ $value }}" @selected($suggestion['audience'] === $value)>New: {{ $label }}</option>@endforeach
                                    </select>
                                    <select name="filters[{{ $filter['id'] }}][parent]" aria-label="Parent filter if {{ $filter['name'] }} is a layer">
                                        <option value="">Layer of…</option>
                                        @foreach($parentFilters->reject(fn ($other) => $other['id'] === $filter['id']) as $other)<option value="{{ $other['id'] }}" @selected($suggestion['parent'] === $other['id'])>Layer of {{ $other['name'] }}</option>@endforeach
                                    </select>
                                    @if($filter['linked'])<small class="network-status ok">Linked to {{ $filter['linked']->name }}</small>@elseif($suggestion['action'] === 'layer')<small class="hint">Looks like a blocklist layer of {{ $filter['parent_name'] }}: same networks{{ $filter['allow'] === [] ? ', no allows' : '' }}.</small>@endif
                                </td>
                                <td><label class="choice compact"><input type="hidden" name="filters[{{ $filter['id'] }}][import]" value="0"><input type="checkbox" name="filters[{{ $filter['id'] }}][import]" value="1" @checked($suggestion['import'])><span>Import its {{ number_format(count($filter['allow']) + count($filter['block'])) }} entries into the policy's own lists</span></label></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
                <p class="hint">Importing keeps every existing controller entry as a managed entry, so nothing is removed on the first push. Entries go into lists that belong to that policy only. Mapped networks are updated to match the controller.</p>
                <div class="form-actions"><button class="button primary" data-confirm="Create and link these policies? Nothing is changed in the controller.">Set up policies</button></div>
            </form>
        </section>
    @endif

    @if($linkedCount)
        <section class="panel policy-section" id="ready">
            <h2>Ready to go</h2>
            <ul class="ready-list">
                @foreach($ready['policies'] as $policy)
                    <li><a href="{{ route('admin.policy.policies.show', $policy) }}"><strong>{{ $policy->name }}</strong></a> → {{ $policy->provider_name }}{{ $policy->isLayer() ? ' · layer of '.$policy->layerOf?->name : ' · '.$policy->audience->label().' filtering' }} <x-sync-status :policy="$policy" /></li>
                @endforeach
                <li>{{ $ready['mapped'] }} of {{ $ready['networks'] }} networks mapped to a policy. <a href="{{ route('admin.policy.networks.index') }}">Networks &amp; filters</a></li>
                <li>Probes: @forelse($ready['profiles'] as $profile){{ $profile->name }} {{ $profile->enabled ? '(on)' : '(off)' }}@if(!$loop->last), @endif @empty none yet @endforelse. <a href="{{ route('admin.settings.show', 'network') }}">Network profiles</a></li>
            </ul>
            <p class="hint">Before switching to Active mode, open each policy and check its change set is empty: after an import, the first push should change nothing.</p>
        </section>
    @endif

    <section class="panel policy-section" id="probes">
        <div class="panel-heading">
            <div><h2>2. Probe containers</h2><p>Each probe sits on one VLAN of its policy and uses that VLAN's gateway for DNS, so checks see the same filtering students or staff do. One probe per policy is enough; other VLANs on the same filter behave the same.</p></div>
            <form method="get" class="inline-search"><input name="parent" value="{{ $parent }}" aria-label="Host interface" placeholder="eth0"><button class="button small secondary">Use interface</button></form>
        </div>
        <details class="probe-why">
            <summary>Why the probes must sit on the VLANs</summary>
            <p>UniFi content filtering works on DNS at the gateway, per network: a lookup is filtered by the filter attached to the network it comes from. A probe on the Docker host's own network would see that network's filtering (or none), not what students or staff get. So each probe needs its own interface on a VLAN the policy covers, an address outside UniFi's DHCP pool, and that VLAN's gateway as its DNS server. The host port on the switch must carry those VLANs tagged (a trunk), and the host interface named below must be that trunk.</p>
        </details>
        @if($extraOptions->isNotEmpty())
            <form method="get" class="extra-probes">
                <input type="hidden" name="parent" value="{{ $parent }}">
                <p><strong>Extra probes</strong> <span class="hint">Test from any other network too, for example guests, BYOD or a single year level, even if it shares a policy.</span></p>
                <div class="category-grid">
                    @foreach($extraOptions as $network)
                        <label class="choice compact"><input type="checkbox" name="extra[]" value="{{ $network['id'] }}" @checked(in_array($network['id'], $extra, true))><span>{{ $network['name'] }}{{ $network['vlan'] ? ' · VLAN '.$network['vlan'] : '' }}<small>{{ $network['policy']?->name ?? (collect($network['attached'])->pluck('name')->join(', ') ?: 'unfiltered') }}</small></span></label>
                    @endforeach
                </div>
                <button class="button small secondary">Update plan</button>
            </form>
        @endif
        @if($plans->isEmpty())
            <p class="hint">Set up and map at least one policy above to get probe recommendations.</p>
        @else
            <div class="notice"><strong>All probes in one file.</strong> <a href="{{ route('admin.policy.setup.compose', ['parent' => $parent, 'extra' => $extra]) }}">Download compose.vlan.yml</a>, or write it on the Docker host with <code>docker compose exec -T app php artisan probes:compose --parent={{ $parent }}@foreach($extra as $id) --network={{ $id }}@endforeach > compose.vlan.yml</code>. The file starts with the network commands and .env lines below.</div>
            <p class="hint">Host interface <code>{{ $parent }}</code> must be a trunk carrying the tagged VLANs. Reserve each suggested <code>/29</code> range outside UniFi's DHCP pool so containers don't clash with clients.</p>
            @foreach($plans as $plan)
                <article class="probe-plan">
                    <h3>{{ $plan['policy']?->name ?? $plan['label'] }} <small>{{ $plan['audience']->label() }} · {{ $plan['network']->name }}{{ $plan['network']->vlan ? ' (VLAN '.$plan['network']->vlan.')' : '' }} · probe <code>{{ $plan['service'] }}</code>{{ $plan['profile'] ? ' · profile '.$plan['profile']->name.($plan['profile']->enabled ? '' : ' (disabled)') : '' }}</small></h3>
                    @foreach($plan['warnings'] as $warning)<p class="network-status warn">{{ $warning }}</p>@endforeach
                    @if($plan['others']->isNotEmpty())<p class="hint">Also covers: {{ $plan['others']->map(fn ($network) => $network->name.($network->vlan ? ' (VLAN '.$network->vlan.')' : ''))->join(', ') }}.</p>@endif
                    <div class="fields two">
                        <label>Create the VLAN network on the Docker host<textarea class="mono" rows="4" readonly id="cmd-{{ $plan['key'] }}">{{ $plan['commands'] }}</textarea><button type="button" class="button small secondary" data-copy="#cmd-{{ $plan['key'] }}">Copy</button></label>
                        <label>{{ $plan['built_in'] ? 'Add to .env' : 'DNS for this probe' }}<textarea class="mono" rows="4" readonly id="env-{{ $plan['key'] }}">{{ $plan['env'] ?? 'PROBE_DNS_SERVERS='.$plan['dns'].'   (set in the service below)' }}</textarea><button type="button" class="button small secondary" data-copy="#env-{{ $plan['key'] }}">Copy</button></label>
                    </div>
                    <label>compose.vlan.yml: under <code>services:</code><textarea class="mono" rows="{{ $plan['built_in'] ? 6 : 18 }}" readonly id="svc-{{ $plan['key'] }}">{{ $plan['compose'] }}</textarea><button type="button" class="button small secondary" data-copy="#svc-{{ $plan['key'] }}">Copy</button></label>
                    <label>compose.vlan.yml: under <code>networks:</code><textarea class="mono" rows="4" readonly id="net-{{ $plan['key'] }}">{{ $plan['compose_network'] }}</textarea><button type="button" class="button small secondary" data-copy="#net-{{ $plan['key'] }}">Copy</button></label>
                </article>
            @endforeach
            <div class="notice">Then run <code>docker compose -f compose.yml -f compose.vlan.yml up -d</code>. For new probes, also add <code>{{ $plans->reject(fn ($plan) => $plan['built_in'])->pluck('service')->map(fn ($service) => $service.': { condition: service_healthy }')->join(', ') ?: 'nothing' }}</code> to the app's <code>depends_on</code> if you want the app to wait for them.</div>
            <form method="post" action="{{ route('admin.policy.setup.profiles') }}">@csrf<input type="hidden" name="parent" value="{{ $parent }}">@foreach($extra as $id)<input type="hidden" name="extra[]" value="{{ $id }}">@endforeach
                <div class="form-actions"><button class="button primary">Create and link probe profiles</button></div>
            </form>
        @endif
    </section>
</x-layouts.app>
