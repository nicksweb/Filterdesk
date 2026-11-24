<x-layouts.app title="Guided setup">
    <x-slot:head><link rel="stylesheet" href="{{ asset('admin.css') }}"></x-slot:head>
    @php
        $stepNames = ['connect' => 'Connect UniFi', 'filters' => 'Match your filters', 'probes' => 'Place the probes', 'check' => 'Switch on and check'];
        $stepIndex = array_flip(array_keys($stepNames));
    @endphp

    <div class="page-heading">
        <div>
            <p class="eyebrow">Web filtering · guided setup</p>
            <h1>Set up filtering</h1>
            <p>Four steps from a connected UniFi console to working website checks. Nothing here changes UniFi.</p>
        </div>
        <a class="button secondary" href="{{ route('admin.policy.setup.show', ['refresh' => 1]) }}">Read UniFi again</a>
    </div>

    <ol class="setup-steps" aria-label="Setup progress">
        @foreach($stepNames as $key => $label)
            <li class="{{ $steps[$key] ? 'done' : ($current === $key ? 'current' : '') }}"><a href="#step-{{ $key }}"><span>{{ $stepIndex[$key] + 1 }}</span>{{ $label }}</a></li>
        @endforeach
    </ol>

    <details class="panel setup-terms">
        <summary>What the words mean</summary>
        <dl>
            <div><dt>Policy</dt><dd>Filterdesk's copy of one UniFi content filter: the sites it allows and blocks. Approved requests are added to it, then published to UniFi.</dd></div>
            <div><dt>Network type</dt><dd>The level of filtering: Students, Staff, Guest and any you add. A policy of a type shares that type's lists, plus every site-wide list.</dd></div>
            <div><dt>Extra blocklist layer</dt><dd>Optional. A second UniFi filter on the same networks that holds large blocklist feeds, because UniFi limits the entries in one filter. Most schools don't need one.</dd></div>
            <div><dt>Probe</dt><dd>A small container placed on a VLAN. It opens websites the way a device on that network would, so checks show what students or staff really get.</dd></div>
            <div><dt>Baseline filtering</dt><dd>A network with no filter at all gets Students filtering, unless you allow it to stay unfiltered and say why (under Networks &amp; filters).</dd></div>
        </dl>
    </details>

    {{-- 1. Connect --}}
    <section class="panel setup-step {{ $steps['connect'] ? 'is-done' : 'is-current' }}" id="step-connect">
        <div class="step-head"><span class="step-number">1</span><div><h2>Connect UniFi</h2>
            @if($state['error'])
                <p class="network-status bad">UniFi could not be read: {{ $state['error'] }}</p>
            @else
                <p>Read {{ count($state['filters']) }} content {{ count($state['filters']) === 1 ? 'filter' : 'filters' }} and {{ count($state['networks']) }} networks{{ $state['fetched_at'] ? ' '.\Illuminate\Support\Carbon::parse($state['fetched_at'])->diffForHumans() : '' }}.</p>
            @endif
        </div></div>
        @if($state['error'])<div class="form-actions"><a class="button primary" href="{{ route('admin.settings.show', 'network') }}">Network provider settings</a></div>@endif
    </section>

    @unless($state['error'])
        {{-- 2. Filters --}}
        <section class="panel setup-step {{ $steps['filters'] ? 'is-done' : ($current === 'filters' ? 'is-current' : '') }}" id="step-filters">
            <div class="step-head"><span class="step-number">2</span><div><h2>Match your UniFi filters</h2>
                <p>Each content filter in UniFi becomes a policy here, so Filterdesk can manage its allow and block lists. Say what each one is for; the suggestion is usually right. Every existing entry is imported, so the first publish changes nothing.</p>
            </div></div>

            @if($filters->isEmpty())
                <p class="network-status warn">UniFi has no content filters yet. Create one per level of filtering in UniFi (for example Students and Staff, under Settings → Security → Content filtering), attach it to its networks, then read UniFi again.</p>
            @else
                @php
                    $describe = function (array $filter) {
                        $suggestion = $filter['suggestion'];
                        if ($suggestion['action'] === 'hours') {
                            return 'School-hours layer of '.($filter['parent_name'] ?? 'its all-hours filter').': keeps its allows, blocks and schedule'.($filter['linked'] && ! $filter['linked']->isHoursLayer() ? ' · now its own policy, will change' : '');
                        }
                        if ($filter['linked'] && ! $filter['linked']->isSitewide() && $suggestion['action'] !== 'sitewide') {
                            return 'Managed by '.$filter['linked']->name.($filter['linked']->isLayer() ? ' (extra blocklist layer)' : ' · '.$filter['linked']->typeLabel().' filtering');
                        }

                        return match ($suggestion['action']) {
                            'sitewide' => 'Site-wide layer (on every network)'.(! empty($suggestion['carries_blocks']) ? ', takes the site-wide blocks' : '').($filter['linked'] && ! $filter['linked']->isSitewide() ? ' · now managed as '.$filter['linked']->typeLabel().', will change' : ''),
                            'layer' => 'Extra blocklist layer on top of '.($filter['parent_name'] ?? 'another filter'),
                            'skip' => 'Left alone',
                            default => \App\Models\NetworkType::labelFor($suggestion['audience']).' filtering',
                        };
                    };
                @endphp
                @php $lessons = $learned['filters']; @endphp
                @if($lessons->isNotEmpty())
                    <div class="learned">
                        <h3>What Filterdesk learned from UniFi</h3>
                        <div class="learned-grid">
                            <div><strong>Site-wide layers</strong><small>On most networks; allows and blocks for everyone</small>
                                <ul>@forelse($lessons->where('role', 'sitewide') as $item)<li>{{ $item['filter']['name'] }} <span class="hint">{{ $item['coverage'] }} networks{{ $item['carries_blocks'] ? ' · takes the site-wide blocks' : '' }}</span></li>@empty<li class="hint">None: each network has its own filter</li>@endforelse</ul></div>
                            <div><strong>Groups</strong><small>One type of user each</small>
                                <ul>@foreach($lessons->whereIn('role', ['group', 'hours'])->groupBy('audience') as $audience => $items)<li>{{ \App\Models\NetworkType::labelFor($audience) }}: {{ $items->map(fn ($item) => $item['filter']['name'].($item['role'] === 'hours' ? ' (school-hours layer: '.$item['when'].')' : ($item['scheduled'] ? ' ('.$item['when'].')' : '')))->join(', ') }}</li>@endforeach</ul></div>
                            @php $kinds = $learned['networks']; @endphp
                            <div><strong>Networks</strong><small>From their names and filters; confirm under Networks &amp; filters</small>
                                <ul><li>{{ $kinds->where('kind', 'user')->count() }} user networks</li><li>{{ $kinds->where('kind', 'device')->count() }} device or infrastructure networks</li>
                                    @if($kinds->whereNotNull('advice')->isNotEmpty())<li><a href="{{ route('admin.policy.networks.index') }}#learned">{{ $kinds->whereNotNull('advice')->count() }} to look at</a></li>@endif</ul></div>
                        </div>
                    </div>
                @endif
                <h3>How your UniFi console will look</h3>
                <p class="hint">Laid out like UniFi's Content Filter page. Bringing everything in changes nothing in UniFi: the same filters, networks, categories, SafeSearch and schedules, with every entry kept. What changes is that Filterdesk manages each filter as shown in the second column. Approvals then go to the filters that need them, and Policies → Changes shows exactly what each publish writes, filter by filter.</p>
                <x-filter-table :rows="$previewRows" :preview="true" />
                <div class="setup-quick">
                    <div>
                        <h3>Recommended: bring everything in</h3>
                        <p class="hint">Uses the choices in the table above. Change any of them first under <em>Change a choice</em>.</p>
                    </div>
                    <form method="post" action="{{ route('admin.policy.setup.import-all') }}">@csrf
                        <button class="button primary" data-confirm="Bring in all {{ $filters->count() }} content filters as shown? Nothing is changed in UniFi.">Bring everything in</button>
                    </form>
                </div>

                <details class="setup-choices">
                    <summary>Change a choice</summary>
                    <form method="post" action="{{ route('admin.policy.setup.store') }}">@csrf
                        @foreach($filters as $filter)
                            @php
                                $suggestion = $filter['suggestion'];
                                $selected = match (true) {
                                    $suggestion['action'] === 'sitewide' => 'sitewide',
                                    $suggestion['action'] === 'hours' => 'hours:'.$suggestion['parent'],
                                    (bool) $filter['linked'] => $filter['linked']->isLayer() ? 'layer:'.$filter['linked']->layerOf?->provider_reference : 'type:'.$filter['linked']->audience,
                                    default => match ($suggestion['action']) { 'layer' => 'layer:'.$suggestion['parent'], 'skip' => 'skip', default => 'type:'.$suggestion['audience'] },
                                };
                                $count = count($filter['allow']) + count($filter['block']);
                            @endphp
                            <div class="filter-choice">
                                <div>
                                    <strong>{{ $filter['name'] }}</strong>
                                    <small>{{ $filter['networks']->map(fn ($network) => $network['name'].($network['vlan'] ? ' (VLAN '.$network['vlan'].')' : ''))->join(', ') ?: 'Not on any network' }} · {{ number_format(count($filter['allow'])) }} allowed, {{ number_format(count($filter['block'])) }} blocked{{ $filter['enabled'] ? '' : ' · disabled in UniFi' }}</small>
                                    @foreach($filter['shared'] as $network)<small class="network-status warn">{{ $network['name'] }} is also in {{ collect($network['attached'])->reject(fn ($other) => $other['id'] === $filter['id'])->pluck('name')->join(', ') }}: devices there get both filters.</small>@endforeach
                                    @if($filter['linked'])<small class="network-status ok">Managed by {{ $filter['linked']->name }}</small>@endif
                                </div>
                                <label>What is it for?
                                    <select name="filters[{{ $filter['id'] }}][as]">
                                        <optgroup label="Filtering for a type of network">
                                            @foreach(\App\Models\NetworkType::options() as $value => $label)<option value="type:{{ $value }}" @selected($selected === 'type:'.$value)>{{ $label }} filtering</option>@endforeach
                                        </optgroup>
                                        <optgroup label="On every network">
                                            <option value="sitewide" @selected($selected === 'sitewide')>Site-wide layer (allows or blocks for everyone)</option>
                                        </optgroup>
                                        @if(($filter['schedule'] ?? 'ALWAYS') !== 'ALWAYS' && $parentFilters->reject(fn ($other) => $other['id'] === $filter['id'])->isNotEmpty())
                                            <optgroup label="On a schedule ({{ $filter['schedule_label'] ?? 'set in UniFi' }})">
                                                @foreach($parentFilters->reject(fn ($other) => $other['id'] === $filter['id']) as $other)<option value="hours:{{ $other['id'] }}" @selected($selected === 'hours:'.$other['id'])>School-hours layer of {{ $other['name'] }} (keeps its allows and blocks)</option>@endforeach
                                            </optgroup>
                                        @endif
                                        @if($parentFilters->reject(fn ($other) => $other['id'] === $filter['id'])->isNotEmpty())
                                            <optgroup label="Optional">
                                                @foreach($parentFilters->reject(fn ($other) => $other['id'] === $filter['id']) as $other)<option value="layer:{{ $other['id'] }}" @selected($selected === 'layer:'.$other['id'])>Extra blocklist layer on top of {{ $other['name'] }}</option>@endforeach
                                            </optgroup>
                                        @endif
                                        <option value="skip" @selected($selected === 'skip')>Leave it alone (not managed here)</option>
                                    </select>
                                </label>
                                <details class="filter-advanced"><summary>Advanced</summary>
                                    <label>Policy to use
                                        <select name="filters[{{ $filter['id'] }}][policy_id]">
                                            <option value="">{{ $filter['linked'] ? 'Keep '.$filter['linked']->name : 'Automatic: an unused policy of that type, or a new one' }}</option>
                                            @foreach($policies as $policy)<option value="{{ $policy->id }}">{{ $policy->name }} ({{ $policy->typeLabel() }}){{ filled($policy->provider_reference) && $policy->provider_reference !== $filter['id'] ? ' · now on '.$policy->provider_name : '' }}</option>@endforeach
                                        </select>
                                    </label>
                                    <label class="choice compact"><input type="hidden" name="filters[{{ $filter['id'] }}][carries_blocks]" value="0"><input type="checkbox" name="filters[{{ $filter['id'] }}][carries_blocks]" value="1" @checked(!empty($suggestion['carries_blocks']))><span>Takes the site-wide blocks<small>Only for a site-wide layer. New site-wide blocks go only into this filter instead of every filter, so each stays under UniFi's entry limit.</small></span></label>
                                    <label class="choice compact"><input type="hidden" name="filters[{{ $filter['id'] }}][import]" value="0"><input type="checkbox" name="filters[{{ $filter['id'] }}][import]" value="1" @checked($filter['linked'] ? false : $suggestion['import'])><span>Import its {{ number_format($count) }} entries<small>Recommended the first time, so publishing keeps them.</small></span></label>
                                </details>
                            </div>
                        @endforeach
                        <div class="form-actions"><button class="button primary" data-confirm="Set these filters up as chosen? Nothing is changed in UniFi.">Save these choices</button></div>
                    </form>
                </details>

                @foreach($duplicates as $group)
                    <p class="network-status warn">{{ $group->count() }} policies are called {{ $group->first()->name }}: @foreach($group as $duplicate)<a href="{{ route('admin.policy.policies.show', $duplicate) }}">{{ $duplicate->slug }}</a>{{ $duplicate->provider_name ? ' (UniFi: '.$duplicate->provider_name.')' : ' (not linked)' }}{{ $loop->last ? '' : ', ' }}@endforeach. Open the extra one and use <em>Merge into another policy</em> to combine them.</p>
                @endforeach
                @if($linkedCount)
                    <p class="hint">{{ $mapped }} of {{ $networkCount }} networks are covered by a policy. Networks with no filter get baseline filtering; review them under <a href="{{ route('admin.policy.networks.index') }}#baseline">Networks &amp; filters</a>.</p>
                @endif
            @endif
        </section>

        {{-- 3. Probes --}}
        <section class="panel setup-step {{ $steps['probes'] ? 'is-done' : ($current === 'probes' ? 'is-current' : '') }}" id="probes">
            <div class="step-head" id="step-probes"><span class="step-number">3</span><div><h2>Place the probes</h2>
                <p>One probe per policy, each on one of that policy's VLANs, so website checks see exactly what that network gets. UniFi filters by DNS at the gateway of each network, so a probe on the server's own network would see the wrong filtering.</p>
            </div></div>

            @if($allPlans->isEmpty())
                <p class="hint">Finish step 2 first: the probes follow the networks your policies cover.</p>
            @else
                <form method="post" action="{{ route('admin.policy.setup.probes') }}">@csrf
                    <h3>a. The Docker host's trunk interface</h3>
                    <div class="trunk-field">
                        <label>Interface name
                            <input name="trunk" value="{{ old('trunk', $saved['trunk']) }}" required maxlength="15" pattern="[A-Za-z0-9_.@-]{1,15}" class="mono" placeholder="for example ens18" @if($saved['trunk'] === '') autofocus @endif>
                        </label>
                        <div class="hint">
                            <p>Filterdesk runs in a container and can't see the host's interfaces, so it can't guess this. On the Docker host, in the Filterdesk folder, run:</p>
                            <pre class="mono">./bin/probe-interfaces</pre>
                            <p>It ends with a line such as <code>Suggested trunk: ens18</code>. Use that name. The switch port for that interface must carry the probe VLANs tagged (in UniFi, a port profile with those VLANs tagged).</p>
                        </div>
                    </div>
                    @if($saved['trunk'] === '')<p class="network-status warn">Not set yet. The commands below show &lt;trunk&gt; until it is.</p>@endif

                    <h3>b. Which networks get a probe</h3>
                    <div class="table-wrap compact"><table>
                        <thead><tr><th>Probe</th><th>Network</th><th>Host interface</th><th>Address</th><th>Probe?</th></tr></thead>
                        <tbody>
                            @foreach($allPlans as $plan)
                                @php($skipped = in_array($plan['network']->provider_network_id, $saved['skip'], true))
                                <tr @class(['plan-skipped' => $skipped])>
                                    <td><strong>{{ $plan['policy']?->name ?? $plan['label'] }}</strong><small><code>{{ $plan['service'] }}</code> · {{ \App\Models\NetworkType::labelFor($plan['audience']) }}</small>
                                        @foreach($plan['warnings'] as $warning)<small class="network-status warn">{{ $warning }}</small>@endforeach
                                        @if($plan['others']->isNotEmpty())<small>Also stands for {{ $plan['others']->map(fn ($network) => $network->name)->join(', ') }}</small>@endif
                                    </td>
                                    <td>{{ $plan['network']->name }}<small>{{ $plan['network']->vlan ? 'VLAN '.$plan['network']->vlan : 'Untagged (native)' }}{{ $plan['subnet'] ? ' · '.$plan['subnet']['network'] : '' }}</small></td>
                                    <td><code>{{ $plan['interface'] }}</code>@if($plan['interface_saved'])<small>set by hand</small>@endif
                                        <details class="interface-override"><summary>Different interface</summary>
                                            <input name="interfaces[{{ $plan['network']->provider_network_id }}]" value="{{ $plan['interface_saved'] ? $plan['interface'] : '' }}" maxlength="15" pattern="[A-Za-z0-9_.@-]{1,15}" class="mono" placeholder="{{ $plan['interface_default'] }}" aria-label="Host interface for {{ $plan['service'] }}">
                                            <small>Only when this network reaches the host another way, such as a separate NIC on an access port. Blank uses the trunk.</small>
                                        </details>
                                    </td>
                                    <td>
                                        <input name="addresses[{{ $plan['network']->provider_network_id }}]" value="{{ old('addresses.'.$plan['network']->provider_network_id, $plan['address_source'] === 'manual' ? $plan['ipv4'] : '') }}" class="mono narrow-ip" inputmode="decimal" placeholder="{{ $plan['address_source'] === 'manual' ? '' : ($plan['ipv4'] ?? 'set by hand') }}" aria-label="Fixed address for {{ $plan['service'] }}">
                                        <small>{{ $plan['address_source'] === 'manual' ? 'Set by hand' : 'Suggested, outside most DHCP pools' }}{{ $plan['subnet'] ? ' · DNS '.$plan['dns'] : '' }}</small>
                                        @if($plan['dhcp_mode'] === 'relay')<small class="network-status warn">DHCP relay: reserve MAC {{ $plan['mac'] }} on your DHCP server</small>@elseif($plan['dhcp_clash'] ?? false)<small class="network-status warn">Inside the DHCP pool: reserve it in UniFi</small>@endif
                                    </td>
                                    <td><label class="choice compact" title="Leave this network without a probe: checks won't show it"><input type="checkbox" name="skip[]" value="{{ $plan['network']->provider_network_id }}" @checked($skipped)><span>No probe<small>For a network the host can't reach, or one not worth testing</small></span></label></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table></div>
                    @if($extraOptions->isNotEmpty())
                        <details class="extra-probes" @if($extra !== []) open @endif><summary>Add a probe for another network <span class="hint">(guests, BYOD, a year level…)</span></summary>
                            <div class="category-grid">
                                @foreach($extraOptions as $network)
                                    <label class="choice compact"><input type="checkbox" name="extra[]" value="{{ $network['id'] }}" @checked(in_array($network['id'], $extra, true))><span>{{ $network['name'] }}{{ $network['vlan'] ? ' · VLAN '.$network['vlan'] : ' · untagged' }}<small>{{ $network['policy']?->name ?? (collect($network['attached'])->pluck('name')->join(', ') ?: 'no filter') }}</small></span></label>
                                @endforeach
                            </div>
                        </details>
                    @endif
                    <p class="hint">A probe needs its network to reach this host: tagged on the trunk's switch port, or, for an untagged network, the trunk's own network. Tick <strong>No probe</strong> for any other (a VPN, another site, a network not on this port); its policy still works, it just isn't shown in checks.</p>
                    <div class="form-actions"><button class="button primary">Save and show the host steps</button></div>
                </form>

                @if($plans->isNotEmpty())
                <div class="host-steps" id="host">
                    <h3>c. On the Docker host</h3>
                    @unless($ready)
                        <p class="network-status warn">Save the trunk interface above first. Until then the commands contain &lt;…&gt; and won't run.</p>
                    @endunless
                    <ol class="numbered-steps">
                        <li>
                            <strong>Create one network per probe</strong> (once). In the Filterdesk folder:
                            <textarea class="mono" rows="{{ min(16, $plans->count() * 4) }}" readonly id="host-networks">{{ $plans->pluck('commands')->join("\n") }}</textarea>
                            <button type="button" class="button small secondary" data-copy="#host-networks">Copy</button>
                            <p class="hint">Check: <code>docker network ls --filter driver=macvlan</code> must list {{ $plans->pluck('docker_network')->map(fn ($name) => $name)->join(', ') }}. If one is missing, Docker refuses to start the whole stack.</p>
                        </li>
                        <li>
                            <strong>Save the compose file</strong> next to compose.yml: <a href="{{ route('admin.policy.setup.compose') }}">download compose.vlan.yml</a>, or write it in place:
                            <pre class="mono">docker compose exec -T app php artisan probes:compose &gt; compose.vlan.yml</pre>
                            <p class="hint">It has everything the probes need, DNS included. Nothing else goes in .env.</p>
                        </li>
                        <li>
                            <strong>List the files in .env</strong>, replacing any <code>COMPOSE_FILE</code> line already there:
                            <pre class="mono" id="compose-line">{{ $composeLine }}</pre>
                            <button type="button" class="button small secondary" data-copy="#compose-line">Copy</button>
                            <p class="hint">{{ $behindProxy ? 'You are using Filterdesk through a reverse proxy, so compose.proxy.yml is included.' : 'Add compose.proxy.yml after compose.yml if a reverse proxy in Docker reaches Filterdesk.' }} Every docker compose command then uses all these files. Don't add <code>-f</code> to commands: it replaces this list, and the web container can drop off the proxy's network (502).</p>
                        </li>
                        <li><strong>Start</strong>: <code>docker compose up -d</code></li>
                    </ol>
                </div>
                @endif
            @endif
        </section>

        {{-- 4. Check --}}
        <section class="panel setup-step {{ $steps['check'] ? 'is-done' : ($current === 'check' ? 'is-current' : '') }}" id="step-check">
            <div class="step-head"><span class="step-number">4</span><div><h2>Switch on and check</h2>
                <p>Each probe's checklist confirms its VLAN, gateway, DNS, internet and DHCP. Switch a probe on once its checklist passes.</p>
            </div></div>
            @if($probeProfiles->isEmpty())
                <p class="hint">The probes appear here after step 3.</p>
            @else
                <ul class="probe-status-list">
                    @foreach($probeProfiles as $profile)
                        @php($diagnosis = app(\App\Services\Website\ProbeDiagnostics::class)->cached($profile))
                        <li><strong>{{ $profile->name }}</strong>
                            @if($diagnosis)<span class="network-status {{ ['ok' => 'ok', 'warn' => 'warn', 'todo' => 'warn', 'fail' => 'bad'][$diagnosis['state']] ?? 'muted' }}">{{ $diagnosis['summary'] }}</span>@else<span class="hint">not checked yet</span>@endif
                            <span class="status {{ $profile->enabled ? 'status-ok' : 'status-disabled' }}">{{ $profile->enabled ? 'On' : 'Off' }}</span>
                        </li>
                    @endforeach
                </ul>
                <div class="form-actions"><a class="button primary" href="{{ route('admin.settings.show', ['section' => 'profiles', 'refresh' => 1]) }}">Run the checklists</a></div>
            @endif
            @if($linked->isNotEmpty())
                <p class="hint">Before switching the network provider to Active mode, open each policy and check nothing is waiting to change: after an import, the first publish should change nothing.</p>
            @endif
        </section>
    @endunless
</x-layouts.app>
