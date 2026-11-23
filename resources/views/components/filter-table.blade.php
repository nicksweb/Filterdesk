@props(['rows', 'preview' => false])
{{-- Laid out like UniFi's Content Filter page, so it reads the same as the console. --}}
<div class="table-wrap filter-table"><table>
    <thead><tr><th>Name</th><th>Filterdesk manages it as</th><th>Networks</th><th>Categories blocked</th><th>SafeSearch</th><th>Schedule</th><th>{{ $preview ? 'Entries' : 'Entries · on publish' }}</th></tr></thead>
    <tbody>
        @foreach($rows as $row)
            <tr @class(['paused' => $row['paused']])>
                <td>@if($row['policy'] && !$preview)<a href="{{ route('admin.policy.policies.show', $row['policy']) }}"><strong>{{ $row['name'] }}</strong></a>@else<strong>{{ $row['name'] }}</strong>@endif
                    @if($row['paused'])<small>Paused in UniFi</small>@endif
                    @if(!$preview && $row['policy']?->sync_status)<x-sync-status :policy="$row['policy']" />@endif</td>
                <td>{{ $row['role'] }}</td>
                <td><span class="chips">@foreach(array_slice($row['networks'], 0, 2) as $network)<span class="chip">{{ $network }}</span>@endforeach @if(count($row['networks']) > 2)<span class="chip more" title="{{ implode(', ', array_slice($row['networks'], 2)) }}">+{{ count($row['networks']) - 2 }}</span>@endif</span></td>
                <td><span class="chips">@foreach(array_slice($row['categories'], 0, 3) as $category)<span class="chip">{{ $category }}</span>@endforeach @if(count($row['categories']) > 3)<span class="chip more" title="{{ implode(', ', array_slice($row['categories'], 3)) }}">+{{ count($row['categories']) - 3 }}</span>@endif</span></td>
                <td>{{ $row['safe_search'] ? implode(', ', $row['safe_search']) : '–' }}</td>
                <td>{{ $row['schedule'] ?: '–' }}</td>
                <td>
                    @if($row['allow'] !== null){{ number_format($row['allow']) }} allowed · {{ number_format($row['block']) }} blocked @endif
                    @if(!$preview && $row['report'])
                        @php($adds = count($row['report']['allow_add']) + count($row['report']['block_add']))
                        @php($removes = count($row['report']['allow_remove']) + count($row['report']['block_remove']))
                        <small @class(['network-status', 'warn' => $removes > 0])>{{ $adds || $removes ? "+{$adds} / −{$removes} waiting" : 'Nothing waiting' }}</small>
                    @endif
                </td>
            </tr>
        @endforeach
    </tbody>
</table></div>
