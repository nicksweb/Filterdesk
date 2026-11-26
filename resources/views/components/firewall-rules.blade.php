@props(['rules'])
{{-- The firewall rules a list keeps in UniFi, as UniFi has them now, with their place in the zone's order. --}}
<div class="table-wrap compact"><table>
    <thead><tr><th>Rule in UniFi</th><th>Zones</th><th>Devices</th><th>Order</th><th>State</th></tr></thead>
    <tbody>
        @foreach($rules as $rule)
            <tr>
                <td><strong>{{ $rule['name'] }}</strong>@if($rule['action'])<small>{{ ucfirst(strtolower($rule['action'])) }}</small>@endif</td>
                <td>{{ $rule['found'] ? $rule['from'].' → '.$rule['to'] : '—' }}</td>
                <td>{{ $rule['sources'] ? implode(', ', $rule['sources']) : 'Every network in the zone' }}</td>
                <td>@if($rule['position']){{ $rule['position'] }} of {{ $rule['of'] }}<small>{{ $rule['before'] ? 'after '.$rule['before'] : 'first' }}{{ $rule['after'] ? ' · before '.$rule['after'] : '' }}</small>@else<span class="hint">—</span>@endif</td>
                <td>@if(! $rule['found'])<span class="status status-failed">Not in UniFi: recreated at the next push</span>@elseif(! $rule['enabled'])<span class="status status-action_required">Disabled in UniFi</span>@else<span class="status status-ok">On</span>@endif</td>
            </tr>
        @endforeach
    </tbody>
</table></div>
