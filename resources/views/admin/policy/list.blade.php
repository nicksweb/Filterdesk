<x-layouts.app title="{{ $list->name }}">
    <x-slot:head><link rel="stylesheet" href="{{ asset('admin.css') }}"></x-slot:head>

    <div class="page-heading">
        <div>
            <p class="eyebrow"><a href="{{ route('admin.policy.index') }}">Policies</a> · {{ $list->scopeLabel() }}</p>
            <h1>{{ $list->name }}</h1>
            <p>{{ $list->kind->label() }} · applies to {{ $policies->pluck('name')->join(', ') ?: 'no enabled policy yet' }}</p>
        </div>
        <a class="button secondary" href="{{ route('admin.policy.lists.export', $list) }}">Download .txt</a>
    </div>

    <div class="content-grid">
        <section class="panel table-wrap policy-section">
            <div class="panel-heading">
                <div><h2>Entries</h2><p>{{ $entries->total() }} hostname(s). Entries from approved requests leave automatically when the request expires or is revoked.</p></div>
                <form method="get" class="inline-search"><input name="q" value="{{ $search }}" placeholder="Filter"><button class="button small secondary">Filter</button></form>
            </div>
            <table>
                <thead><tr><th>Hostname</th><th>Source</th><th>Window</th><th></th></tr></thead>
                <tbody>
                    @forelse($entries as $entry)
                        <tr @class(['muted-row' => !$entry->isEffective()])>
                            <td><strong>{{ $entry->domain }}</strong>@if($entry->note)<small>{{ $entry->note }}</small>@endif</td>
                            <td>{{ $entry->source->label() }}@if($entry->websiteRequest)<small><a href="{{ route('requests.show', $entry->website_request_id) }}">{{ $entry->websiteRequest->reference }}</a></small>@elseif($entry->addedBy)<small>{{ $entry->addedBy->name }}</small>@endif</td>
                            <td>@if($entry->isScheduled())Starts {{ $entry->starts_at->local()->format('j M Y g:i a') }}@elseif($entry->expires_at){{ $entry->expires_at->isPast() ? 'Expired' : 'Until' }} {{ $entry->expires_at->local()->format('j M Y g:i a') }}@else Permanent @endif</td>
                            <td>
                                <form method="post" action="{{ route('admin.policy.entries.destroy', $entry) }}">@csrf @method('DELETE')
                                    <button class="button small secondary" data-confirm="{{ $entry->source === \App\Enums\EntrySource::Request ? 'This entry came from an approved request. Remove it anyway? (Revoking the request is usually better.)' : 'Remove '.$entry->domain.'?' }}">Remove</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="empty">No entries yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            {{ $entries->links() }}
        </section>

        <div class="stack">
            @if($list->feed)
            <section class="panel policy-section">
                <h2>Filled from a blocklist</h2>
                <p class="hint">{{ $list->feed->name }} replaces this list's entries every {{ $list->feed->refresh_hours }} hour(s). To exempt a site, add it to an allow list for a network type or policy, which overrides a site-wide list. Manage the feed under <a href="{{ route('admin.policy.blocklists.index') }}">Blocklists &amp; firewall</a>.</p>
            </section>
            @else
            <section class="panel policy-section">
                <h2>Add hostnames</h2>
                <form method="post" action="{{ route('admin.policy.entries.store') }}">@csrf
                    <input type="hidden" name="policy_list_id" value="{{ $list->id }}">
                    <label>Hostnames<textarea name="domains" rows="4" required class="mono" placeholder="example.com&#10;www.example.com&#10;cdn.example.net"></textarea></label>
                    <div class="fields two">
                        <label>Starts<input type="datetime-local" name="starts_at"></label>
                        <label>Expires<input type="datetime-local" name="expires_at"></label>
                    </div>
                <label class="choice compact"><input type="hidden" name="include_www" value="0"><input type="checkbox" name="include_www" value="1" @checked(old('include_www', true))><span><strong>Include www. and the bare domain</strong><small>Typing example.com also adds www.example.com, and the other way round.</small></span></label>
                    <label>Note<input name="note"></label>
                    <div class="form-actions"><button class="button primary">Add</button></div>
                </form>
            </section>

            <section class="panel policy-section">
                <h2>Bulk import</h2>
                <form method="post" action="{{ route('admin.policy.lists.import', $list) }}">@csrf
                    <label>Paste a list<textarea name="domains" rows="6" class="mono"></textarea></label>
                    <label>Mode<select name="mode"><option value="merge">Merge, keeping existing entries</option><option value="replace">Replace manual and imported entries (request entries are kept)</option></select></label>
                    <div class="form-actions"><button class="button secondary" data-confirm="Import into {{ $list->name }}?">Import</button></div>
                </form>
            </section>

            @endif

            @if(auth()->user()->isAdmin())
                <section class="panel policy-section">
                    <h2>List settings</h2>
                    <form method="post" action="{{ route('admin.policy.lists.update', $list) }}">@csrf @method('PUT')
                        <label>Name<input name="name" required value="{{ $list->name }}"></label>
                        <div class="fields two">
                            <label>Type<select name="kind">@foreach(\App\Enums\ListKind::cases() as $kind)<option value="{{ $kind->value }}" @selected($list->kind === $kind)>{{ $kind->label() }}</option>@endforeach</select></label>
                            <label>Applies to<select name="scope" data-scope-select>@foreach(\App\Enums\ListScope::cases() as $scope)<option value="{{ $scope->value }}" @selected($list->scope === $scope)>{{ $scope->label() }}</option>@endforeach</select></label>
                        </div>
                        <label data-scope-field="audience">Network type<select name="audience">@foreach(\App\Models\NetworkType::options() as $value => $label)<option value="{{ $value }}" @selected($list->audience === $value)>{{ $label }}</option>@endforeach</select></label>
                        <label data-scope-field="policy">Policy<select name="filter_policy_id">@foreach($allPolicies as $policy)<option value="{{ $policy->id }}" @selected($list->filter_policy_id === $policy->id)>{{ $policy->name }}</option>@endforeach</select></label>
                        <label>Description<input name="description" value="{{ $list->description }}"></label>
                        <label class="choice compact"><input type="hidden" name="is_default" value="0"><input type="checkbox" name="is_default" value="1" @checked($list->is_default)><span><strong>Default list</strong><small>Approved requests for this network type are added to the default allow list.</small></span></label>
                        <div class="form-actions"><button class="button primary">Save</button></div>
                    </form>
                    <form method="post" action="{{ route('admin.policy.lists.destroy', $list) }}">@csrf @method('DELETE')<button class="link-button danger-text" data-confirm="Delete {{ $list->name }} and all of its entries?">Delete list</button></form>
                </section>
            @endif
        </div>
    </div>
</x-layouts.app>
