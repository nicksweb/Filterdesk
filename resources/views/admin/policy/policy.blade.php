<x-layouts.app title="{{ $policy->name }}">
    <x-slot:head><link rel="stylesheet" href="{{ asset('admin.css') }}"></x-slot:head>

    <div class="page-heading">
        <div>
            <p class="eyebrow"><a href="{{ route('admin.policy.index') }}">Policy lists</a> · {{ $policy->audience->label() }}@if($policy->layerOf) · layer of <a href="{{ route('admin.policy.policies.show', $policy->layerOf) }}">{{ $policy->layerOf->name }}</a>@endif</p>
            <h1>{{ $policy->name }}</h1>
            <p>{{ $policy->description ?: 'Content filter policy' }}{{ $policy->provider_reference ? ' · Controller filter: '.$policy->provider_reference : '' }}</p>
        </div>
        @if($report['in_sync'])<span class="status status-ok">In sync</span>@elseif($report['has_snapshot'])<span class="status status-action_required">Changes due</span>@else<span class="status status-pending">No snapshot</span>@endif
    </div>

    @if(filled($policy->provider_reference))
        @php($details = $policy->sync_details ?? [])
        <section @class(['panel', 'policy-section', 'sync-panel', 'sync-'.($policy->sync_status ?? 'unknown')])>
            <div class="panel-heading">
                <div><h2>Sync with {{ $policy->provider_name ?: 'the controller' }} <x-sync-status :policy="$policy" /></h2>
                    <p>{{ $details['summary'] ?? 'Not checked yet.' }} {{ $policy->sync_checked_at ? 'Checked '.$policy->sync_checked_at->diffForHumans().'.' : '' }} Checked automatically every 15 minutes{{ ($details['authoritative'] ?? false) ? '; changes made in the controller are pulled in, because it is the source of truth' : '' }}.</p></div>
                <div class="button-row">
                    <form method="post" action="{{ route('admin.policy.policies.pull', $policy) }}">@csrf<button class="button secondary">Check now</button></form>
                    @if($policy->sync_status === 'drift' && collect($details['changed_in_controller'] ?? [])->flatten()->isNotEmpty())
                        <form method="post" action="{{ route('admin.policy.policies.accept-controller', $policy) }}">@csrf<button class="button secondary" data-confirm="Accept the controller's current lists as intended? Entries added there stay unmanaged unless you import them below.">Accept controller changes</button></form>
                    @endif
                </div>
            </div>
            @if(collect($details['changed_in_controller'] ?? [])->flatten()->isNotEmpty())
                <div class="sync-changes">
                    <p><strong>Edited directly in the controller</strong> since {{ isset($policy->sync_baseline['at']) ? \Illuminate\Support\Carbon::parse($policy->sync_baseline['at'])->local()->format('j M g:i a') : 'the last push' }}:</p>
                    @foreach(['allow_added' => 'Allowed in the controller', 'allow_removed' => 'Allow entries removed in the controller', 'block_added' => 'Blocked in the controller', 'block_removed' => 'Block entries removed in the controller', 'categories_added' => 'Categories blocked in the controller', 'categories_removed' => 'Categories unblocked in the controller'] as $key => $label)
                        @if(!empty($details['changed_in_controller'][$key]))<p class="hint"><strong>{{ $label }}:</strong> {{ implode(', ', $details['changed_in_controller'][$key]) }}</p>@endif
                    @endforeach
                    <p class="hint">Import entries you want to keep (below), push to restore removed managed entries, or accept the controller's state.</p>
                </div>
            @endif
            @if(!empty($details['pulled']))
                <div class="sync-pulled">
                    <p><strong>Last pulled from the controller</strong> {{ isset($details['pulled']['at']) ? \Illuminate\Support\Carbon::parse($details['pulled']['at'])->diffForHumans() : '' }}:</p>
                    @foreach(['allow_imported' => 'Allowed there, now managed here', 'block_imported' => 'Blocked there, now managed here', 'allow_excluded' => 'Allow entries removed there', 'block_excluded' => 'Block entries removed there', 'restored' => 'Re-added there', 'categories_changed' => 'Categories changed there'] as $key => $label)
                        @if(!empty($details['pulled'][$key]))<p class="hint"><strong>{{ $label }}:</strong> {{ implode(', ', $details['pulled'][$key]) }}</p>@endif
                    @endforeach
                </div>
            @endif
            @php($excluded = $policy->exclusions())
            @if($excluded['allow'] || $excluded['block'])
                <div class="sync-excluded">
                    <p><strong>Removed in the controller, kept out of this filter</strong>. Restore any that should go back on the next push.</p>
                    @foreach(['allow' => 'Allow', 'block' => 'Block'] as $kind => $label)
                        @if($excluded[$kind])
                            <form method="post" action="{{ route('admin.policy.policies.restore', $policy) }}" class="adopt-form">@csrf<input type="hidden" name="kind" value="{{ $kind }}">
                                <div class="check-scroll">@foreach($excluded[$kind] as $domain)<label><input type="checkbox" name="domains[]" value="{{ $domain }}"> {{ $domain }} <small>({{ strtolower($label) }})</small></label>@endforeach</div>
                                <button class="button small secondary">Restore selected {{ strtolower($label) }} entries</button>
                            </form>
                        @endif
                    @endforeach
                </div>
            @endif
            @if(!empty($details['networks']))<p class="hint"><strong>Mapped networks not using this filter:</strong> {{ implode(', ', $details['networks']) }}. Attach the filter to them in the controller, or change the mapping under <a href="{{ route('admin.policy.networks.index') }}">Networks &amp; filters</a>.</p>@endif
        </section>
    @endif

    <section class="panel policy-section">
        <div class="panel-heading">
            <div><h2>1. Current controller lists</h2><p>
                @if($canPull)Fetched from the controller filter <strong>{{ $policy->provider_name ?: $policy->provider_reference }}</strong>@if($policy->networkAssignments->isNotEmpty()) · mapped networks: {{ $policy->networkAssignments->map(fn ($network) => $network->name.($network->vlan ? ' (VLAN '.$network->vlan.')' : ''))->join(', ') }}@endif. You can also paste them below.
                @else Copy the allow and block lists from this content filter in the controller, or link it under <a href="{{ route('admin.policy.networks.index') }}">Networks &amp; filters</a> to fetch them. Escaped dots, schemes, paths and wildcard prefixes are normalised.@endif
            </p></div>
            <div class="button-row">
                @if($policy->snapshot_at)<small>Last compared {{ $policy->snapshot_at->diffForHumans() }}{{ $policy->snapshotBy ? ' by '.$policy->snapshotBy->name : '' }}</small>@endif
                @if($canPull)<form method="post" action="{{ route('admin.policy.policies.pull', $policy) }}">@csrf<button class="button primary">Fetch from controller</button></form>@endif
            </div>
        </div>
        <details @if(!$canPull) open @endif>
            <summary>Paste lists manually</summary>
            <form method="post" action="{{ route('admin.policy.policies.snapshot', $policy) }}">@csrf
                <div class="fields two policy-editors">
                    <label>Current allow list<textarea name="current_allowlist" rows="10">{{ implode("\n", $report['current_allow']) }}</textarea></label>
                    <label>Current block list<textarea name="current_blocklist" rows="10">{{ implode("\n", $report['current_block']) }}</textarea></label>
                </div>
                <div class="form-actions"><button class="button secondary">Save snapshot and compare</button></div>
            </form>
        </details>
    </section>

    <section class="panel policy-section">
        <div class="panel-heading"><div><h2>2. Change set</h2><p>{{ $report['has_snapshot'] ? 'Differences between the controller and the desired state.' : 'No snapshot yet, so everything desired is shown as an addition.' }}</p></div></div>
        @if($report['controller_blocks_allowed'])
            <div class="alert error"><strong>The controller blocks hostnames that are allowed here:</strong> {{ implode(', ', $report['controller_blocks_allowed']) }}. Remove them from the controller's block list, or remove the allow entry if the block is intended.</div>
        @endif
        <div class="change-grid">
            @foreach([
                ['Add to allow list', 'allow_add', 'positive', 'Desired but missing from the controller.', null],
                ['Remove from allow list', 'allow_remove', 'negative', 'Previously managed here (expired, revoked or removed).', null],
                ['Only in the controller: allowed', 'allow_review', 'neutral', 'Added in the controller by hand. Import them to keep them, or remove them from the controller.', 'allow'],
                ['Add to block list', 'block_add', 'negative', 'Desired blocks missing from the controller.', null],
                ['Remove from block list', 'block_remove', 'positive', 'Blocked in the controller but removed here or allowed by a managed list.', null],
                ['Only in the controller: blocked', 'block_review', 'neutral', 'Blocked in the controller by hand. Import them to keep them, or remove them from the controller.', 'block'],
            ] as [$label, $key, $tone, $help, $adoptKind])
                <article class="change-card {{ $tone }}">
                    <div><h3>{{ $label }}</h3><span>{{ count($report[$key]) }}</span></div>
                    <p class="hint">{{ $help }}</p>
                    @if($adoptKind && count($report[$key]) && $adoptTargets[$adoptKind]->isNotEmpty())
                        <form method="post" action="{{ route('admin.policy.policies.adopt', $policy) }}" class="adopt-form">@csrf
                            <input type="hidden" name="kind" value="{{ $adoptKind }}">
                            <div class="check-scroll">
                                @foreach($report[$key] as $domain)<label><input type="checkbox" name="domains[]" value="{{ $domain }}" checked> {{ $domain }}</label>@endforeach
                            </div>
                            <label>Import into
                                <select name="policy_list_id">
                                    @foreach($adoptTargets[$adoptKind] as $target)<option value="{{ $target->id }}" @selected($target->scope === \App\Enums\ListScope::Audience)>{{ $target->name }} ({{ $target->scopeLabel() }})</option>@endforeach
                                </select>
                            </label>
                            <button class="button small primary">Import selected</button>
                        </form>
                    @else
                        <textarea rows="6" readonly id="change-{{ $key }}">{{ implode("\n", $report[$key]) }}</textarea>
                        @if(count($report[$key]))<button type="button" class="button small secondary" data-copy="#change-{{ $key }}">Copy</button>@endif
                    @endif
                </article>
            @endforeach
        </div>
        @if($report['conflicts'])<div class="notice"><strong>In both an allow and a block list.</strong> The more specific list wins (this policy, then its audience, then site-wide); at the same level the most recent entry wins. @foreach($report['resolved'] as $domain => $winner){{ $domain }}: {{ $winner === 'block' ? 'blocked' : 'allowed' }}@if(!$loop->last) · @endif @endforeach</div>@endif
        @if($report['shadowed'])<div class="notice"><strong>Blocked parent domains with allowed subdomains:</strong> @foreach($report['shadowed'] as $pair){{ $pair['allow'] }} (under blocked {{ $pair['block'] }})@if(!$loop->last), @endif @endforeach. Check the controller gives allow-list entries priority.</div>@endif
    </section>

    @php($pending = count($report['allow_add']) + count($report['allow_remove']) + count($report['block_add']) + count($report['block_remove']))
    <section class="panel policy-section push-panel">
        <div class="panel-heading">
            <div><h2>Push to the controller</h2><p>
                @if($canPush)
                    Applies the change set above to <strong>{{ $policy->provider_name ?: $policy->provider_reference }}</strong>. The live filter is re-read first; entries added in UniFi by hand are kept, and the result is read back and recorded.{{ $autoPush ? ' Automatic pushing is on.' : '' }}
                @elseif(blank($policy->provider_reference))
                    Link this policy to a controller filter under <a href="{{ route('admin.policy.networks.index') }}">Networks &amp; filters</a> to push changes.
                @else
                    Pushing is off. @if(auth()->user()->isAdmin())Set the network provider to <a href="{{ route('admin.settings.show', 'network') }}">Active</a> to enable it.@else An admin can enable it.@endif
                @endif
            </p></div>
            <div class="button-row">
                @if(auth()->user()->isAdmin() && filled($policy->provider_reference) && $canPull)
                    <form method="post" action="{{ route('admin.policy.policies.verify-write', $policy) }}">@csrf<button class="button secondary" data-confirm="Save {{ $policy->provider_name ?: 'the linked filter' }} back to UniFi unchanged to check this key can write it?">Verify write access</button></form>
                @endif
                @if($canPush)
                    <form method="post" action="{{ route('admin.policy.policies.push', $policy) }}">@csrf<button class="button primary" data-confirm="Push to {{ $policy->provider_name }}: allow +{{ count($report['allow_add']) }} / −{{ count($report['allow_remove']) }}, block +{{ count($report['block_add']) }} / −{{ count($report['block_remove']) }} (based on the last fetch; the live filter is re-read first)?">{{ $pending ? 'Push '.$pending.' change(s)' : 'Push now' }}</button></form>
                @endif
            </div>
        </div>
        @if($pushes->isNotEmpty())
            <div class="table-wrap compact"><table><thead><tr><th>When</th><th>By</th><th>Result</th><th>Changes</th><th></th></tr></thead><tbody>
                @foreach($pushes as $push)
                    <tr>
                        <td>{{ $push->created_at->local()->format('j M g:i a') }}<small>{{ ucfirst($push->trigger) }}</small></td>
                        <td>{{ $push->user?->name ?? 'Automatic' }}</td>
                        <td><span class="status status-{{ $push->status === 'ok' ? 'ok' : 'failed' }}">{{ $push->status === 'ok' ? 'Applied' : 'Failed' }}</span><small>{{ $push->message }}</small></td>
                        <td>@foreach(['allow_add' => 'Allowed', 'allow_remove' => 'Unallowed', 'block_add' => 'Blocked', 'block_remove' => 'Unblocked'] as $key => $label)@if(!empty($push->changes[$key]))<small><strong>{{ $label }}:</strong> {{ implode(', ', $push->changes[$key]) }}</small>@endif @endforeach</td>
                        <td>@if($canPush && $push->status === 'ok' && !$push->rollback_of_id && $push->changeCount())<form method="post" action="{{ route('admin.policy.pushes.rollback', $push) }}">@csrf<button class="button small secondary" data-confirm="Put {{ $push->provider_name }} back to its lists from before this push?">Roll back</button></form>@endif</td>
                    </tr>
                @endforeach
            </tbody></table></div>
        @endif
    </section>

    @php($largest = max(count($report['allow']), count($report['block'])))
    @if($largest > $slowListSize)
        <div class="notice"><strong>{{ number_format($largest) }} entries in one list.</strong> UniFi accepts up to about 100,000, but its Content Filtering page loads every entry, so opening or editing {{ $policy->provider_name ?: 'this filter' }} in the UniFi web interface may be slow. Filtering itself is not affected.@if(!$policy->isLayer() && !$policy->layer) A layer keeps the feeds in a second filter so this one stays quick to manage.@endif</div>
    @endif

    @if($policy->isLayer())
        <section class="panel policy-section layer-panel">
            <h2>Layer of {{ $policy->layerOf?->name }}</h2>
            <p>{{ $policy->provider_name }} is a second content filter on the same networks as {{ $policy->layerOf?->provider_name ?: $policy->layerOf?->name }}. UniFi applies both, and a block in either one wins, so this layer only blocks. It carries every blocklist feed that applies to {{ $policy->layerOf?->name }}, plus this layer's own block lists. Anything {{ $policy->layerOf?->name }} allows is left out, so allow lists and approved requests still win. Its content categories follow {{ $policy->layerOf?->name }}.</p>
        </section>
    @elseif($policy->layer)
        <section class="panel policy-section layer-panel">
            <h2>Layer: <a href="{{ route('admin.policy.policies.show', $policy->layer) }}">{{ $policy->layer->name }}</a></h2>
            <p>Blocklist feeds for this policy are pushed to {{ $policy->layer->provider_name ?: $policy->layer->name }}, a second filter on the same networks, so {{ $policy->provider_name ?: 'this filter' }} only holds entries added by IT and requests.@if(!$policy->activeLayer()) The layer is disabled or not linked, so feeds stay here for now.@endif</p>
        </section>
    @elseif($canPush && auth()->user()->isAdmin())
        <details class="panel policy-section layer-panel">
            <summary><strong>Add a layer for blocklist feeds</strong> · a second UniFi filter on the same networks</summary>
            <p class="hint">UniFi lets several content filters cover one network and applies all of them; a block in any one wins. A layer moves this policy's blocklist feeds into their own filter, so {{ $policy->provider_name }} stays short and quick to edit in UniFi. The app leaves anything this policy allows out of the layer, so allow lists and approvals keep working. The layer copies this filter's content categories (UniFi needs at least one).</p>
            <form method="post" action="{{ route('admin.policy.policies.layer.store', $policy) }}">@csrf
                <label>Layer filter name<input name="name" maxlength="80" required value="{{ old('name', ($policy->provider_name ?: $policy->name).' - Blocklists') }}"></label>
                <label class="choice compact"><input type="checkbox" name="push" value="1" checked><span><strong>Move the feeds now</strong><small>Pushes the layer first, then removes the feeds from {{ $policy->provider_name }}, so nothing goes unblocked in between.</small></span></label>
                <div class="form-actions"><button class="button primary" data-confirm="Create a new content filter in UniFi on the same networks as {{ $policy->provider_name }}?">Create layer in UniFi</button></div>
            </form>
        </details>
    @endif

    @if($categories && auth()->user()->isAdmin())
        <details class="panel policy-section categories-panel">
            <summary><strong>Content categories</strong> · {{ count($categories['blocked']) }} of {{ count($categories['available']) }} blocked in {{ $policy->provider_name }}</summary>
            <form method="post" action="{{ route('admin.policy.policies.categories', $policy) }}">@csrf
                <p class="hint">Categories are blocked by the controller's category database for every network this filter applies to. Allow-list entries still override them. Changes are saved straight to the controller, read back, and recorded in the audit log.</p>
                <div class="button-row"><button type="button" class="button small secondary" data-check-all="category">Select all</button><button type="button" class="button small secondary" data-check-none="category">Select none</button></div>
                <div class="category-grid">
                    @foreach($categories['available'] as $category)
                        <label class="choice compact"><input type="checkbox" name="categories[]" value="{{ $category }}" data-group="category" @checked(in_array($category, $categories['blocked'], true))><span>{{ str($category)->replace('_', ' ')->lower()->ucfirst() }}</span></label>
                    @endforeach
                </div>
                <div class="form-actions"><button class="button primary" data-confirm="Save the blocked categories to {{ $policy->provider_name }}? This changes filtering on every network it applies to.">Save categories to controller</button></div>
            </form>
        </details>
    @endif

    <section class="panel policy-section">
        <div class="panel-heading">
            <div><h2>3. Desired lists</h2><p>Everything this policy should allow and block, one hostname per line. Useful for checking, or for pasting by hand when pushing is off.</p></div>
            <form method="post" action="{{ route('admin.policy.policies.synced', $policy) }}">@csrf<button class="button secondary" data-confirm="Record that the controller now matches these desired lists?">Mark controller in sync</button></form>
        </div>
        <div class="fields two policy-editors">
            <div><label>Desired allow list ({{ count($report['allow']) }})<textarea rows="14" readonly id="desired-allow">{{ implode("\n", $report['allow']) }}</textarea></label>
                <div class="button-row"><button type="button" class="button small secondary" data-copy="#desired-allow">Copy</button><a class="button small secondary" href="{{ route('admin.policy.policies.export', [$policy, 'allow']) }}">Download .txt</a></div></div>
            <div><label>Desired block list ({{ count($report['block']) }})<textarea rows="14" readonly id="desired-block">{{ implode("\n", $report['block']) }}</textarea></label>
                <div class="button-row"><button type="button" class="button small secondary" data-copy="#desired-block">Copy</button><a class="button small secondary" href="{{ route('admin.policy.policies.export', [$policy, 'block']) }}">Download .txt</a></div></div>
        </div>
        <ul class="rule-notes">@foreach($rules->notes as $note)<li>{{ $note }}</li>@endforeach</ul>
    </section>

    <div class="content-grid">
        <section class="panel table-wrap policy-section">
            <div class="panel-heading"><div><h2>Inherited lists</h2><p>Every list feeding this policy.</p></div></div>
            <table><thead><tr><th>List</th><th>Scope</th><th>Type</th><th>Entries</th></tr></thead><tbody>
                @foreach($lists as $list)
                    <tr><td><a href="{{ route('admin.policy.lists.show', $list) }}">{{ $list->name }}</a></td><td>{{ $list->scopeLabel() }}</td><td>{{ $list->kind->label() }}</td><td>{{ $list->entries_count }}</td></tr>
                @endforeach
            </tbody></table>
        </section>

        @if(auth()->user()->isAdmin())
            <section class="panel policy-section">
                <h2>Policy settings</h2>
                <form method="post" action="{{ route('admin.policy.policies.update', $policy) }}">@csrf @method('PUT')
                    <label>Name<input name="name" required value="{{ $policy->name }}"></label>
                    <label>Audience <span class="optional">decides which audience lists apply</span><select name="audience">@foreach(\App\Enums\Audience::options() as $value => $label)<option value="{{ $value }}" @selected($policy->audience->value === $value)>{{ $label }}</option>@endforeach</select></label>
                    <label>Network probe<select name="network_profile_id"><option value="">None</option>@foreach($profiles as $profile)<option value="{{ $profile->id }}" @selected($policy->network_profile_id === $profile->id)>{{ $profile->name }}</option>@endforeach</select></label>
                    <label>Controller content filter <span class="optional">name or ID</span><input name="provider_reference" value="{{ $policy->provider_reference }}"></label>
                    <label>Description<input name="description" value="{{ $policy->description }}"></label>
                    <div class="fields two"><label>Sort order<input type="number" name="sort_order" value="{{ $policy->sort_order }}"></label>
                        <label class="choice compact"><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked($policy->enabled)><span><strong>Enabled</strong></span></label></div>
                    <div class="form-actions"><button class="button primary">Save</button></div>
                </form>
                <form method="post" action="{{ route('admin.policy.policies.destroy', $policy) }}">@csrf @method('DELETE')<button class="link-button danger-text" data-confirm="Delete this policy and its own lists?">Delete policy</button></form>
            </section>
        @endif
    </div>
</x-layouts.app>
