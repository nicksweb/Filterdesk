<x-layouts.app title="Changes">
    <x-slot:head><link rel="stylesheet" href="{{ asset('admin.css') }}"></x-slot:head>
    <div class="page-heading">
        <div>
            <p class="eyebrow">Policy</p>
            <h1>Changes</h1>
            <p>What is waiting for a second IT officer, what publishing would change in the controller, and every published version.</p>
        </div>
        @if($canPublish)
            <form method="post" action="{{ route('admin.policy.changes.publish') }}">@csrf
                <button class="button primary" @disabled($plan['total'] === 0) data-confirm="Publish {{ $plan['total'] }} waiting change(s) to the controller as a new policy version?">Publish {{ $plan['total'] ?: '' }} change{{ $plan['total'] === 1 ? '' : 's' }}</button>
            </form>
        @endif
    </div>
    <x-push-status :status="$publishStatus" />

    @php($entries = $awaiting['entries'])
    @php($requests = $awaiting['requests'])
    <section class="panel policy-section">
        <div class="panel-heading"><div><h2>Waiting for a second IT officer</h2><p>Site-wide changes and approvals in sensitive categories need two IT officers. The person who proposed a change cannot confirm it.</p></div></div>
        @if($entries->isEmpty() && $requests->isEmpty())
            <p class="empty">Nothing is waiting.</p>
        @endif
        @if($requests->isNotEmpty())
            <h3>Requests</h3>
            <ul class="plain-list">
                @foreach($requests as $item)
                    <li><a href="{{ route('requests.show', $item) }}">{{ $item->reference }}</a> · {{ implode(', ', $item->domains) }} <small>first approved by {{ $item->first_approval['name'] ?? 'unknown' }} · {{ implode('; ', $item->first_approval['reasons'] ?? []) }}</small></li>
                @endforeach
            </ul>
        @endif
        @if($entries->isNotEmpty())
            <h3>Site-wide list changes</h3>
            <form method="post" action="{{ route('admin.policy.changes.decide') }}">@csrf
                <div class="table-wrap"><table>
                    <thead><tr><th><span class="sr-only">Select</span></th><th>Change</th><th>List</th><th>Proposed</th></tr></thead>
                    <tbody>
                        @foreach($entries as $entry)
                            <tr>
                                <td><input type="checkbox" name="entries[]" value="{{ $entry->id }}" checked aria-label="Select {{ $entry->domain }}"></td>
                                <td><span class="status status-{{ $entry->pending_action === 'add' ? 'pending' : 'action_required' }}">{{ $entry->pending_action === 'add' ? 'Add' : 'Remove' }}</span> <code>{{ $entry->domain }}</code></td>
                                <td>{{ $entry->policyList->name }} <small>{{ $entry->policyList->kind->value }}</small></td>
                                <td>{{ $entry->proposedBy?->name ?? 'Unknown' }}<small>{{ $entry->proposed_at?->local()->format('j M Y g:i a') }}</small>@if($entry->proposed_by_id === auth()->id())<small>You proposed this</small>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
                @if($entries->contains('proposed_by_id', auth()->id()) && app(\App\Services\Approval\TwoPersonRule::class)->overrideAllowed())
                    <details class="override">
                        <summary>No other IT officer is available?</summary>
                        <label class="choice compact"><input type="hidden" name="override" value="0"><input type="checkbox" name="override" value="1"><span><strong>Confirm my own changes without a second IT officer</strong><small>Only when nobody else can confirm in time. The other IT officers are emailed so someone reviews it, and it is recorded in the audit log.</small></span></label>
                        <label>Why no one else is available<input name="override_reason" maxlength="1000"></label>
                    </details>
                @endif
                <div class="button-row">
                    <button class="button primary" name="decision" value="confirm">Confirm selected</button>
                    <button class="button secondary" name="decision" value="reject">Turn down selected</button>
                </div>
            </form>
        @endif
    </section>

    <section class="panel policy-section">
        <div class="panel-heading"><div><h2>Ready to publish</h2><p>Worked out against the last read of each controller filter. Publishing writes all of it as one numbered version; nothing here is live until then (unless automatic pushing is on, which publishes request decisions as they happen).</p></div></div>
        @if($plan['total'] === 0)
            <p class="empty">The controller matches the policy. Nothing to publish.</p>
        @endif
        @foreach($plan['filters'] as $item)
            @if(! $item['ok'])
                <p class="network-status warn"><strong>{{ $item['policy']->name }}</strong>: {{ $item['message'] }}</p>
            @elseif($item['count'] > 0)
                <details class="plan-filter">
                    <summary><strong>{{ $item['policy']->name }}</strong> · {{ $item['message'] }}</summary>
                    @foreach(['allow_add' => 'Allow +', 'allow_remove' => 'Allow −', 'block_add' => 'Block +', 'block_remove' => 'Block −'] as $key => $label)
                        @if($item['changes'][$key])
                            <p><strong>{{ $label }}</strong> {{ count($item['changes'][$key]) }}: @foreach(array_slice($item['changes'][$key], 0, 50) as $domain)<code>{{ $domain }}</code>@if(!$loop->last), @endif @endforeach{{ count($item['changes'][$key]) > 50 ? ' and '.(count($item['changes'][$key]) - 50).' more' : '' }}</p>
                        @endif
                    @endforeach
                </details>
            @endif
        @endforeach
        @foreach($plan['firewall'] as $item)
            <p class="hint"><strong>{{ $item['list']->name }}</strong> (firewall IP list): {{ $item['reason'] }}</p>
        @endforeach
    </section>

    <section class="panel policy-section">
        <div class="panel-heading"><div><h2>Versions</h2><p>Every write to the controller belongs to a version: published by hand, after a request decision, by a schedule or by a blocklist refresh. Roll back a version to put its content filters back as they were before it.</p></div></div>
        <div class="table-wrap"><table>
            <thead><tr><th>Version</th><th>When</th><th>How</th><th>Changes</th><th>Status</th><th></th></tr></thead>
            <tbody>
                @forelse($releases as $release)
                    <tr>
                        <td><a href="{{ route('admin.policy.versions.show', $release) }}"><strong>{{ $release->label() }}</strong></a></td>
                        <td>{{ $release->created_at->local()->format('j M Y g:i a') }}<small>{{ $release->user?->name ?? 'Automatic' }}</small></td>
                        <td>{{ $release->triggerLabel() }}@if($release->rollback_of_id)<small>Rollback of v{{ $release->rollbackOf?->number }}</small>@endif</td>
                        <td>{{ $release->summary }}</td>
                        <td><span class="status status-{{ ['published' => 'ok', 'partial' => 'action_required', 'failed' => 'failed'][$release->status] ?? 'pending' }}">{{ ucfirst($release->status) }}</span></td>
                        <td>@if($canPublish && $release->canRollBack())<form method="post" action="{{ route('admin.policy.versions.rollback', $release) }}">@csrf<button class="link-button" data-confirm="Put the content filters in {{ $release->label() }} back as they were before it?">Roll back</button></form>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty">No versions yet. The first publish creates v1.</td></tr>
                @endforelse
            </tbody>
        </table></div>
        {{ $releases->links() }}
    </section>
</x-layouts.app>
