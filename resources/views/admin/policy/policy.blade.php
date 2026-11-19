<x-layouts.app title="{{ $policy->name }}">
    <x-slot:head><link rel="stylesheet" href="{{ asset('admin.css') }}"></x-slot:head>

    <div class="page-heading">
        <div>
            <p class="eyebrow"><a href="{{ route('admin.policy.index') }}">Policy lists</a> · {{ $policy->audience->label() }}</p>
            <h1>{{ $policy->name }}</h1>
            <p>{{ $policy->description ?: 'Content filter policy' }}{{ $policy->provider_reference ? ' · Controller filter: '.$policy->provider_reference : '' }}</p>
        </div>
        @if($report['in_sync'])<span class="status status-ok">In sync</span>@elseif($report['has_snapshot'])<span class="status status-action_required">Changes due</span>@else<span class="status status-pending">No snapshot</span>@endif
    </div>

    <section class="panel policy-section">
        <div class="panel-heading"><div><h2>1. Paste the controller's current lists</h2><p>Copy the allow and block lists from this content filter in the controller. Escaped dots, schemes, paths and wildcard prefixes are normalised.</p></div>@if($policy->snapshot_at)<small>Last compared {{ $policy->snapshot_at->diffForHumans() }}{{ $policy->snapshotBy ? ' by '.$policy->snapshotBy->name : '' }}</small>@endif</div>
        <form method="post" action="{{ route('admin.policy.policies.snapshot', $policy) }}">@csrf
            <div class="fields two policy-editors">
                <label>Current allow list<textarea name="current_allowlist" rows="10">{{ implode("\n", $report['current_allow']) }}</textarea></label>
                <label>Current block list<textarea name="current_blocklist" rows="10">{{ implode("\n", $report['current_block']) }}</textarea></label>
            </div>
            <div class="form-actions"><button class="button primary">Save snapshot and compare</button></div>
        </form>
    </section>

    <section class="panel policy-section">
        <div class="panel-heading"><div><h2>2. Change set</h2><p>{{ $report['has_snapshot'] ? 'Differences between the snapshot and the desired state.' : 'No snapshot yet, so everything desired is shown as an addition.' }}</p></div></div>
        <div class="change-grid">
            @foreach([
                ['Add to allow list', 'allow_add', 'positive', 'Desired but missing from the controller.'],
                ['Remove from allow list', 'allow_remove', 'negative', 'Previously managed here (expired, revoked or removed).'],
                ['Review in allow list', 'allow_review', 'neutral', 'In the controller but never managed here. Import it into a list or remove it.'],
                ['Add to block list', 'block_add', 'negative', 'Desired blocks missing from the controller.'],
                ['Remove from block list', 'block_remove', 'positive', 'Blocked in the controller but not desired.'],
            ] as [$label, $key, $tone, $help])
                <article class="change-card {{ $tone }}">
                    <div><h3>{{ $label }}</h3><span>{{ count($report[$key]) }}</span></div>
                    <p class="hint">{{ $help }}</p>
                    <textarea rows="6" readonly id="change-{{ $key }}">{{ implode("\n", $report[$key]) }}</textarea>
                    @if(count($report[$key]))<button type="button" class="button small secondary" data-copy="#change-{{ $key }}">Copy</button>@endif
                </article>
            @endforeach
        </div>
        @if($report['conflicts'])<div class="alert error"><strong>Allow/block conflicts</strong><p>Allow wins in the output. Resolve these in the lists: {{ implode(', ', $report['conflicts']) }}</p></div>@endif
        @if($report['shadowed'])<div class="notice"><strong>Blocked parent domains with allowed subdomains:</strong> @foreach($report['shadowed'] as $pair){{ $pair['allow'] }} (under blocked {{ $pair['block'] }})@if(!$loop->last), @endif @endforeach. Check the controller gives allow-list entries priority.</div>@endif
    </section>

    <section class="panel policy-section">
        <div class="panel-heading">
            <div><h2>3. Desired lists</h2><p>Copy-ready, one hostname per line. Paste the full lists into the controller, then record the policy as in sync.</p></div>
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
