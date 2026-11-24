@props(['rows', 'preview' => false])
{{-- Laid out like UniFi's Content Filter page, so it reads the same as the console. --}}
<div class="table-wrap filter-table"><table>
    <thead><tr><th>Name</th><th>Filterdesk manages it as</th><th>Networks</th><th>Categories blocked</th><th>SafeSearch</th><th>Schedule</th><th>{{ $preview ? 'Entries' : 'Entries, and what publishing changes' }}</th></tr></thead>
    <tbody>
        @foreach($rows as $row)
            <tr @class(['paused' => $row['paused']])>
                <td>@if($row['policy'] && !$preview)<a href="{{ route('admin.policy.policies.show', $row['policy']) }}"><strong>{{ $row['name'] }}</strong></a>@else<strong>{{ $row['name'] }}</strong>@endif
                    @if($row['paused'])<small>Paused in UniFi</small>@endif
                    @if(!$preview && $row['policy']?->sync_status)<x-sync-status :policy="$row['policy']" />@endif</td>
                <td>{{ $row['role'] }}</td>
                <td><x-chips :items="$row['networks']" :max="2" /></td>
                <td><x-chips :items="$row['categories']" :max="3" /></td>
                <td>{{ $row['safe_search'] ? implode(', ', $row['safe_search']) : '–' }}</td>
                <td>{{ $row['schedule'] ?: '–' }}</td>
                <td>
                    @if(!$preview && ($row['gone'] ?? false))
                        @php($ownEntries = \App\Models\PolicyEntry::query()->whereIn('policy_list_id', $row['policy']->ownLists()->pluck('id'))->count())
                        <span class="network-status warn">Not pushed: delete it, keep it unlinked, or link it to another filter.</span>
                        @if(auth()->user()->isAdmin())
                            <span class="row-fixes">
                                <form method="post" action="{{ route('admin.policy.policies.unlink', $row['policy']) }}">@csrf<button class="button small secondary">Keep, unlinked</button></form>
                                <a class="button small secondary" href="{{ route('admin.policy.networks.index') }}">Link to another filter…</a>
                                <form method="post" action="{{ route('admin.policy.policies.destroy', $row['policy']) }}">@csrf @method('DELETE')<button class="button small secondary" data-confirm="Delete the policy {{ $row['policy']->name }}?{{ $ownEntries ? ' Its own lists ('.$ownEntries.' entries) are deleted too: move any you need to a site-wide or network type list first.' : '' }} Site-wide and network type lists are not changed.">Delete policy</button></form>
                            </span>
                        @endif
                    @endif
                    @if($row['allow'] !== null){{ number_format($row['allow']) }} allowed · {{ number_format($row['block']) }} blocked @endif
                    @if(!$preview && $row['report'])
                        @php($adds = count($row['report']['allow_add']) + count($row['report']['block_add']))
                        @php($removes = count($row['report']['allow_remove']) + count($row['report']['block_remove']))
                        @if($adds || $removes)
                            <details class="pending"><summary @class(['network-status', 'warn' => $removes > 0])>{{ $adds ? $adds.' to add' : '' }}{{ $adds && $removes ? ', ' : '' }}{{ $removes ? $removes.' to remove' : '' }} on publish</summary>
                                @foreach(['allow_add' => 'Allow +', 'block_add' => 'Block +', 'allow_remove' => 'Allow −', 'block_remove' => 'Block −'] as $key => $label)
                                    @if($row['report'][$key])<small><strong>{{ $label }}</strong> {{ implode(', ', array_slice($row['report'][$key], 0, 12)) }}{{ count($row['report'][$key]) > 12 ? ' and '.(count($row['report'][$key]) - 12).' more' : '' }}</small>@endif
                                @endforeach
                                @if($row['policy'])<a href="{{ route('admin.policy.policies.show', $row['policy']) }}">See why, on the policy page</a>@endif
                            </details>
                        @else
                            <small class="network-status">Nothing waiting</small>
                        @endif
                    @endif
                </td>
            </tr>
        @endforeach
    </tbody>
</table></div>
