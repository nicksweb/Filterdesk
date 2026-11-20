<x-layouts.app title="Blocklists & firewall">
    <x-slot:head><link rel="stylesheet" href="{{ asset('admin.css') }}"></x-slot:head>

    <div class="page-heading">
        <div>
            <p class="eyebrow">Web filtering</p>
            <h1>Blocklists &amp; firewall</h1>
            <p>Subscribe to third-party lists. Domain lists feed the content filters like any other list; IP lists keep a controller IP group up to date for a firewall rule to block.</p>
        </div>
    </div>

    <section class="panel policy-section">
        <div class="panel-heading"><div><h2>Add a list</h2><p>Content filters push at most {{ number_format($maxFilterEntries) }} entries per list; IP lists go to a firewall group instead. To keep a policy's own filter quick to edit in UniFi, give it a layer (on the policy page) and its block feeds go to that second filter. Lists refresh automatically. Approvals and lists for one audience or policy still override a site-wide feed, so an approved request is never blocked by a feed.</p></div></div>
        <form method="post" action="{{ route('admin.policy.blocklists.feeds.store') }}">@csrf
            <label>Preset
                <select name="preset">
                    <option value="">Custom list (enter below)</option>
                    @foreach($presets->groupBy('group', preserveKeys: true) as $group => $items)
                        <optgroup label="{{ $group }}">
                            @foreach($items as $key => $preset)<option value="{{ $key }}">{{ $preset['name'] }} · {{ $preset['type'] === 'ips' ? 'IPs' : 'domains' }} · ~{{ number_format($preset['size']) }}</option>@endforeach
                        </optgroup>
                    @endforeach
                </select>
            </label>
            <details class="preset-help" open><summary>About the presets</summary>
                @foreach(config('blocklists.publishers') as $key => $publisher)
                    <div class="publisher">
                        <p><strong><a href="{{ $publisher['url'] }}" rel="noopener" target="_blank">{{ $publisher['name'] }}</a></strong> · maintained by {{ $publisher['maintainer'] }} · licence <a href="{{ $publisher['licence_url'] }}" rel="noopener" target="_blank">{{ $publisher['licence'] }}</a></p>
                        <p class="hint">{{ $publisher['summary'] }} {{ $publisher['format'] }} The lists are downloaded from the publisher when you add them and on each refresh; none of their content is shipped with IT Requests. Check a list's description in the repository before blocking with it, and report false positives to the publisher.</p>
                        <table class="preset-table"><thead><tr><th>Preset</th><th>Type</th><th>Size</th><th>What it blocks</th></tr></thead><tbody>
                            @foreach($presets->where('publisher', $key) as $preset)
                                <tr><td><strong>{{ str($preset['name'])->after(': ') }}</strong><small>{{ $preset['group'] }} · <a href="{{ $preset['url'] }}" rel="noopener" target="_blank">raw list</a></small></td><td>{{ $preset['type'] === 'ips' ? 'IPs' : 'Domains' }}</td><td>~{{ number_format($preset['size']) }}</td><td>{{ $preset['description'] }}</td></tr>
                            @endforeach
                        </tbody></table>
                    </div>
                @endforeach
                <p class="hint">Sizes are approximate and change daily. Set <em>DNS filter entry matching</em> in Settings → Network provider to match how your controller treats subdomains.</p>
            </details>
            <div class="fields three">
                <label>Custom name<input name="name" placeholder="Our partner allow list"></label>
                <label>Custom URL <span class="optional">https</span><input name="url" type="url" placeholder="https://example.org/list.txt"></label>
                <label>Contains<select name="type"><option value="domains">Domains (hosts, adblock or plain)</option><option value="ips">IP addresses / subnets</option></select></label>
            </div>
            <div class="fields three">
                <label>Domain list type<select name="kind"><option value="block">Block list</option><option value="allow">Allow list</option></select></label>
                <label>Applies to<select name="scope" data-scope-select><option value="site">Site-wide (every policy)</option><option value="audience">An audience</option><option value="policy">One policy</option></select></label>
                <label data-scope-field="audience">Audience<select name="audience">@foreach(\App\Enums\Audience::options() as $value => $label)<option value="{{ $value }}" @selected($value === 'student')>{{ $label }}</option>@endforeach</select></label>
                <label data-scope-field="policy">Policy<select name="filter_policy_id">@foreach($policies as $policy)<option value="{{ $policy->id }}">{{ $policy->name }}</option>@endforeach</select></label>
            </div>
            <div class="fields three">
                <label>IP lists go into<select name="firewall_list_id"><option value="">A new firewall IP list</option>@foreach($firewallLists as $list)<option value="{{ $list->id }}">{{ $list->name }} ({{ strtoupper($list->family) }})</option>@endforeach</select></label>
                <label>Refresh every (hours)<input type="number" name="refresh_hours" min="1" max="168" value="24"></label>
                <label>Keep at most <span class="optional">entries</span><input type="number" name="max_entries" min="10" max="{{ config('blocklists.max_entries_limit') }}" placeholder="20,000 domains / 4,000 IPs"></label>
            </div>
            <div class="form-actions"><button class="button primary">Add and download</button></div>
        </form>
    </section>

    @if($feedLoad->contains(fn ($item) => $item['count'] > 0))
        <div class="notice"><strong>Feed domains per content filter:</strong> @foreach($feedLoad as $item){{ $item['policy']->name }} {{ number_format($item['count']) }}@if(!$loop->last) · @endif @endforeach.
            Pushes are refused above {{ number_format($maxFilterEntries) }} entries per list (Settings → Network provider; UniFi handles about 100,000). Filtering is not slowed by long lists, but UniFi's Content Filtering page is: above a few thousand entries it is slow to open and edit. A layer keeps the feeds in their own filter.</div>
    @endif

    <section class="panel table-wrap policy-section">
        <div class="panel-heading"><div><h2>Subscribed lists</h2></div></div>
        <table>
            <thead><tr><th>List</th><th>Goes into</th><th>Entries</th><th>Last download</th><th>Settings</th><th></th></tr></thead>
            <tbody>
                @forelse($sources as $source)
                    <tr @class(['muted-row' => !$source->enabled])>
                        <td><strong>{{ $source->name }}</strong>@php($publisher = $source->preset ? config('blocklists.publishers.'.config("blocklists.presets.{$source->preset}.publisher")) : null)<small>@if($publisher)From <a href="{{ $publisher['url'] }}" rel="noopener" target="_blank">{{ $publisher['name'] }}</a> ({{ $publisher['licence'] }}) · @endif<a href="{{ $source->url }}" rel="noopener" target="_blank">{{ str($source->url)->after('://')->limit(60) }}</a></small></td>
                        <td>@if($source->policyList)<a href="{{ route('admin.policy.lists.show', $source->policyList) }}">{{ $source->policyList->name }}</a><small>{{ $source->policyList->kind->label() }} · {{ $source->policyList->scopeLabel() }}</small>@elseif($source->firewallList){{ $source->firewallList->name }}<small>Firewall IP list</small>@endif</td>
                        <td>{{ number_format($source->entry_count) }}<small>max {{ number_format($source->max_entries) }}</small></td>
                        <td>@if($source->last_fetched_at)<span class="status status-{{ $source->last_status === 'failed' ? 'failed' : ($source->last_status === 'truncated' ? 'action_required' : 'ok') }}">{{ ucfirst($source->last_status) }}</span><small>{{ $source->last_fetched_at->diffForHumans() }} · {{ $source->last_message }}</small>@else<span class="hint">Never</span>@endif</td>
                        <td>
                            <form method="post" action="{{ route('admin.policy.blocklists.feeds.update', $source) }}" class="feed-settings">@csrf @method('PUT')
                                <label class="toggle"><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked($source->enabled)> On</label>
                                <input type="number" name="refresh_hours" min="1" max="168" value="{{ $source->refresh_hours }}" aria-label="Refresh hours" title="Refresh every N hours">
                                <input type="number" name="max_entries" min="10" max="{{ config('blocklists.max_entries_limit') }}" value="{{ $source->max_entries }}" aria-label="Maximum entries" title="Maximum entries">
                                <button class="button small secondary">Save</button>
                            </form>
                        </td>
                        <td class="row-actions">
                            <form method="post" action="{{ route('admin.policy.blocklists.feeds.refresh', $source) }}">@csrf<button class="button small secondary">Refresh</button></form>
                            <form method="post" action="{{ route('admin.policy.blocklists.feeds.destroy', $source) }}">@csrf @method('DELETE')<button class="link-button danger-text" data-confirm="Remove {{ $source->name }} and all its entries?">Remove</button></form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty">No lists yet. The DNS bypass preset is a good first choice.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <section class="panel policy-section">
        <div class="panel-heading">
            <div><h2>Firewall IP lists</h2><p>Each list keeps one controller IP group (UniFi: a traffic matching list, Settings → Profiles) set to its addresses, through the official API. Then a firewall rule in the controller blocks traffic to that group, for example <em>Block · Internal → External · destination: this group</em>. The app manages the group's contents, not the rule.</p></div>
        </div>
        @if($groupsError)<div class="alert error">Controller IP groups could not be read: {{ $groupsError }}</div>@endif
        @foreach($firewallLists as $list)
            @php($desired = $list->desiredEntries())
            <article class="probe-plan">
                <h3>{{ $list->name }} <small>{{ strtoupper($list->family) }} · {{ number_format($desired['total']) }} address(es) from {{ count($list->manual_entries ?? []) }} manual + {{ $list->sources_count }} feed(s){{ $list->provider_reference ? ' · controller group '.($list->provider_name ?: $list->provider_reference) : ' · not linked' }}</small></h3>
                @if($list->last_pushed_at)<p class="hint"><span class="status status-{{ $list->last_push_status === 'failed' ? 'failed' : ($list->last_push_status === 'truncated' ? 'action_required' : 'ok') }}">{{ ucfirst($list->last_push_status) }}</span> {{ $list->last_pushed_at->diffForHumans() }} · {{ $list->last_push_message }}</p>@endif
                @if($list->provider_reference && is_array($list->referenced_by))
                    @if($list->referenced_by === [])<p class="network-status warn">No firewall rule uses this group yet, so nothing is blocked. Add a Block rule in the controller with this group as the destination.</p>
                    @else<p class="hint">Used by: @foreach($list->referenced_by as $rule){{ $rule['name'] }} ({{ $rule['action'] }}{{ $rule['enabled'] ? '' : ', disabled' }})@if(!$loop->last), @endif @endforeach</p>@endif
                @endif
                <form method="post" action="{{ route('admin.policy.blocklists.firewall.update', $list) }}">@csrf @method('PUT')
                    <div class="fields three">
                        <label>Name<input name="name" value="{{ $list->name }}" required></label>
                        <label>Controller IP group
                            <select name="provider_reference">
                                <option value="">Not linked</option>
                                @foreach(collect($groups)->where('family', $list->family) as $group)<option value="{{ $group['id'] }}" @selected($list->provider_reference === $group['id'])>{{ $group['name'] }} ({{ $group['count'] }})</option>@endforeach
                                @if($list->provider_reference && !collect($groups)->contains('id', $list->provider_reference))<option value="{{ $list->provider_reference }}" selected>{{ $list->provider_name ?: $list->provider_reference }}</option>@endif
                            </select>
                        </label>
                        <label>Keep at most<input type="number" name="max_entries" min="1" max="{{ config('blocklists.max_entries_limit') }}" value="{{ $list->max_entries }}"></label>
                    </div>
                    <label>Your own addresses <span class="optional">one per line; single IPs or CIDR subnets</span><textarea name="manual_entries" rows="4" class="mono" placeholder="203.0.113.10&#10;198.51.100.0/24">{{ implode("\n", $list->manual_entries ?? []) }}</textarea></label>
                    <label class="choice compact"><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked($list->enabled)><span><strong>Enabled</strong><small>Pushed automatically after feed refreshes when Push automatically is on.</small></span></label>
                    <div class="button-row"><button class="button secondary">Save</button></div>
                </form>
                <div class="button-row">
                    @if($canWrite && $list->provider_reference)<form method="post" action="{{ route('admin.policy.blocklists.firewall.push', $list) }}">@csrf<button class="button primary" data-confirm="Replace the addresses in {{ $list->provider_name }} with this list's {{ number_format(min($desired['total'], $list->max_entries)) }} address(es)?">Push to controller</button></form>@endif
                    @if($canWrite && !$list->provider_reference)<form method="post" action="{{ route('admin.policy.blocklists.firewall.create-group', $list) }}">@csrf<button class="button primary" data-confirm="Create an IP group called {{ $list->name }} in the controller?">Create IP group in controller</button></form>@endif
                    @if(!$canWrite)<span class="hint">Set the network provider to Active to push.</span>@endif
                    <form method="post" action="{{ route('admin.policy.blocklists.firewall.destroy', $list) }}">@csrf @method('DELETE')<button class="link-button danger-text" data-confirm="Delete {{ $list->name }} and its feeds? The controller group is left in place.">Delete</button></form>
                </div>
            </article>
        @endforeach
        <form method="post" action="{{ route('admin.policy.blocklists.firewall.store') }}" class="inline-create">@csrf
            <div class="fields three">
                <label>New firewall IP list<input name="name" required placeholder="Blocked IPs"></label>
                <label>Family<select name="family"><option value="ipv4">IPv4</option><option value="ipv6">IPv6</option></select></label>
                <div class="form-actions"><button class="button secondary">Create</button></div>
            </div>
        </form>
    </section>
</x-layouts.app>
