<x-layouts.app title="Policy lists">
    <x-slot:head><link rel="stylesheet" href="{{ asset('admin.css') }}"></x-slot:head>

    <div class="page-heading">
        <div>
            <p class="eyebrow">Web filtering</p>
            <h1>Policy lists</h1>
            <p>Manage allow and block lists site-wide, per audience and per policy, then reconcile each controller policy.</p>
        </div>
    </div>

    <div class="policy-stats">
        <article><span>Filter policies</span><strong>{{ $policies->count() }}</strong></article>
        <article><span>Managed lists</span><strong>{{ $lists->count() }}</strong></article>
        <article><span>Approvals to apply</span><strong>{{ $actionRequired }}</strong>@if($actionRequired)<a href="{{ route('requests.index', ['status' => 'action_required']) }}">Review</a>@endif</article>
        <article><span>Policies out of sync</span><strong>{{ $policies->reject(fn ($item) => $item['policy']->sync_status ? $item['policy']->sync_status === 'in_sync' : $item['report']['in_sync'])->count() }}</strong>@if($policies->contains(fn ($item) => $item['policy']->sync_status === 'drift'))<a href="{{ route('admin.policy.networks.index') }}">Changed in controller</a>@endif</article>
    </div>

    <section class="policy-section">
        <div class="section-heading"><h2>Filter policies</h2><p>One per content filter in the controller. Desired state = site-wide lists + audience lists + the policy's own lists + approved requests.</p></div>
        <div class="policy-cards">
            @foreach($policies as ['policy' => $policy, 'report' => $report])
                <a class="panel policy-card" href="{{ route('admin.policy.policies.show', $policy) }}">
                    <div class="policy-card-top"><span><strong>{{ $policy->name }}</strong><small>{{ $policy->audience->label() }}{{ $policy->provider_name ? ' · '.$policy->provider_name : '' }}{{ $policy->networkProfile ? ' · '.$policy->networkProfile->name : '' }}</small></span>
                        @if(!$policy->enabled)<x-status status="disabled" />@elseif(filled($policy->provider_reference))<x-sync-status :policy="$policy" />@elseif(!$report['has_snapshot'])<span class="status status-pending">No snapshot</span>@elseif($report['in_sync'])<span class="status status-ok">In sync</span>@else<span class="status status-action_required">Changes due</span>@endif
                    </div>
                    <dl class="mini-stats">
                        <div><dt>Allow</dt><dd>{{ count($report['allow']) }}</dd></div>
                        <div><dt>Block</dt><dd>{{ count($report['block']) }}</dd></div>
                        <div><dt>To add</dt><dd>{{ count($report['allow_add']) + count($report['block_add']) }}</dd></div>
                        <div><dt>To remove</dt><dd>{{ count($report['allow_remove']) + count($report['block_remove']) }}</dd></div>
                    </dl>
                    <small>@if($policy->sync_checked_at){{ $policy->sync_details['summary'] ?? '' }} Checked {{ $policy->sync_checked_at->diffForHumans() }}.@else{{ $policy->snapshot_at ? 'Compared '.$policy->snapshot_at->diffForHumans() : 'Paste the controller lists to compare' }}@endif</small>
                </a>
            @endforeach
        </div>
        @if(auth()->user()->isAdmin())
            <details class="panel inline-create">
                <summary>Add a filter policy</summary>
                <form method="post" action="{{ route('admin.policy.policies.store') }}">@csrf
                    <div class="fields three">
                        <label>Name<input name="name" required placeholder="Guests"></label>
                        <label>Audience<select name="audience">@foreach(\App\Enums\Audience::options() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                        <label>Controller content filter <span class="optional">name or ID</span><input name="provider_reference" placeholder="Guest Wi-Fi filter"></label>
                    </div>
                    <p class="hint">The audience decides which lists the policy inherits: Staff, Student or Guest access lists, plus every site-wide list.</p>
                    <div class="form-actions"><button class="button primary">Create policy</button></div>
                </form>
            </details>
        @endif
    </section>

    <div class="content-grid">
        <section class="panel policy-section">
            <div class="panel-heading"><div><h2>Add hostnames</h2><p>Add domains directly when there is no request. Site-wide entries reach every policy, including students. To find every hostname a site uses, try the <a href="{{ route('admin.policy.scan.show') }}">domain scanner</a>.</p></div></div>
            <form method="post" action="{{ route('admin.policy.entries.store') }}">@csrf
                <label>List
                    <select name="policy_list_id" required>
                        @foreach($lists as $list)<option value="{{ $list->id }}" @selected(old('policy_list_id') == $list->id)>{{ $list->name }} ({{ $list->scopeLabel() }} · {{ $list->kind->value }})</option>@endforeach
                    </select>
                </label>
                <label>Hostnames <span class="optional">one per line; schemes, paths and *. are stripped</span><textarea name="domains" rows="4" required class="mono">{{ old('domains') }}</textarea></label>
                <div class="fields two">
                    <label>Starts <span class="optional">optional</span><input type="datetime-local" name="starts_at" value="{{ old('starts_at') }}"></label>
                    <label>Expires <span class="optional">blank = permanent</span><input type="datetime-local" name="expires_at" value="{{ old('expires_at') }}"></label>
                </div>
                <p class="hint">Timed entries work on allow and block lists, so a site can be opened or closed for a lesson or exam. They go live {{ \App\Models\PolicyEntry::margins()['lead'] }} min early and are removed {{ \App\Models\PolicyEntry::margins()['lag'] }} min late to allow for syncing.</p>
                <label>Note <span class="optional">why it was added</span><input name="note" value="{{ old('note') }}"></label>
                <div class="form-actions"><button class="button primary">Add to list</button></div>
            </form>
        </section>

        <div class="stack">
            <section class="panel policy-section">
                <h2>Where is a hostname?</h2>
                <form method="get" class="search-line"><label>Hostname<input name="lookup" value="{{ $lookup['domain'] ?? '' }}" placeholder="cdn.example.com"></label><button class="button secondary">Look up</button></form>
                @if($lookup)
                    @if(!$lookup['valid'])<p class="hint">That is not a valid hostname.</p>
                    @elseif($lookup['entries']->isEmpty())<p class="hint">{{ $lookup['domain'] }} is not in any managed list.</p>
                    @else
                        @foreach($lookup['entries'] as $entry)
                            <div class="list-row static"><span><strong>{{ $entry->domain }}</strong><small>{{ $entry->policyList->name }} · {{ $entry->policyList->scopeLabel() }} · {{ $entry->source->label() }}{{ $entry->websiteRequest ? ' '.$entry->websiteRequest->reference : '' }}{{ $entry->expires_at ? ' · until '.$entry->expires_at->local()->format('j M Y') : '' }}</small></span><span class="status status-{{ $entry->policyList->kind === \App\Enums\ListKind::Allow ? 'ok' : 'rejected' }}">{{ $entry->policyList->kind->value }}</span></div>
                        @endforeach
                    @endif
                @endif
            </section>
            <section class="panel policy-section">
                <h2>Recently added</h2>
                @forelse($recent as $entry)
                    <div class="list-row static"><span><strong>{{ $entry->domain }}</strong><small><a href="{{ route('admin.policy.lists.show', $entry->policyList) }}">{{ $entry->policyList->name }}</a> · {{ $entry->source->label() }}{{ $entry->websiteRequest ? ' '.$entry->websiteRequest->reference : ($entry->addedBy ? ' by '.$entry->addedBy->name : '') }}</small></span><small>{{ $entry->created_at->diffForHumans() }}</small></div>
                @empty
                    <p class="hint">No entries yet.</p>
                @endforelse
            </section>
            <section class="panel policy-section">
                <h2>Next 48 hours</h2>
                @forelse($upcoming['starting']->concat($upcoming['expiring']) as $entry)
                    <div class="list-row static"><span><strong>{{ $entry->domain }}</strong><small>{{ $entry->policyList->name }}{{ $entry->websiteRequest ? ' · '.$entry->websiteRequest->reference : '' }}</small></span><small>{{ $entry->isScheduled() ? 'Goes live '.$entry->effectiveFrom()->diffForHumans() : 'Removed '.$entry->effectiveUntil()->diffForHumans() }}</small></div>
                @empty
                    <p class="hint">No scheduled starts or expiries.</p>
                @endforelse
            </section>
        </div>
    </div>

    <section class="panel table-wrap policy-section">
        <div class="panel-heading"><div><h2>Lists</h2><p>Site-wide lists apply to every policy; audience lists apply to every policy for that audience; policy lists apply to one policy.</p></div></div>
        <table>
            <thead><tr><th>List</th><th>Applies to</th><th>Type</th><th>Entries</th><th></th></tr></thead>
            <tbody>
                @foreach($lists as $list)
                    <tr>
                        <td><a href="{{ route('admin.policy.lists.show', $list) }}"><strong>{{ $list->name }}</strong></a>@if($list->is_default && $list->kind === \App\Enums\ListKind::Allow)<small>Receives approved requests</small>@elseif($list->description)<small>{{ str($list->description)->limit(80) }}</small>@endif</td>
                        <td>{{ $list->scopeLabel() }}</td>
                        <td><span class="status status-{{ $list->kind === \App\Enums\ListKind::Allow ? 'ok' : 'rejected' }}">{{ $list->kind->label() }}</span></td>
                        <td>{{ $list->entries_count }}@if($list->request_entries_count)<small>{{ $list->request_entries_count }} from requests</small>@endif</td>
                        <td><a class="button small secondary" href="{{ route('admin.policy.lists.show', $list) }}">Manage</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if(auth()->user()->isAdmin())
            <details class="inline-create">
                <summary>Create a list</summary>
                <form method="post" action="{{ route('admin.policy.lists.store') }}">@csrf
                    <div class="fields three">
                        <label>Name<input name="name" required placeholder="Exam-week allow"></label>
                        <label>Type<select name="kind"><option value="allow">Allow list</option><option value="block">Block list</option></select></label>
                        <label>Applies to<select name="scope" data-scope-select><option value="site">Site-wide (every policy)</option><option value="audience">An audience</option><option value="policy">One policy</option></select></label>
                    </div>
                    <div class="fields two">
                        <label data-scope-field="audience">Audience<select name="audience">@foreach(\App\Enums\Audience::options() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                        <label data-scope-field="policy">Policy<select name="filter_policy_id">@foreach($policies as ['policy' => $policy])<option value="{{ $policy->id }}">{{ $policy->name }}</option>@endforeach</select></label>
                    </div>
                    <label>Description <span class="optional">optional</span><input name="description"></label>
                    <div class="form-actions"><button class="button primary">Create list</button></div>
                </form>
            </details>
        @endif
    </section>
</x-layouts.app>
