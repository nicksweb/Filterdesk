@props(['list', 'managesRules' => true, 'details' => []])
{{-- The UniFi IP lists a Filterdesk list keeps, so IT knows what to pick in their own rules; new parts, and rules made here before. --}}
@if($list->groups)
    <p class="hint">In UniFi: @foreach($list->groups as $group)<code>{{ $group['name'] }}</code> ({{ number_format($group['count'] ?? 0) }}){{ ! empty($group['spare']) ? ' (spare: still used by a rule, emptied)' : '' }}@if(!$loop->last), @endif @endforeach.</p>
@endif
@if(! $managesRules)
    <p class="notice">Filterdesk keeps these UniFi IP lists up to date. Use them in your own firewall rules in UniFi{{ count($list->groups ?? []) > 1 ? ', every part of them' : '' }}.</p>
@endif
@if($list->new_parts)
    <div class="alert error"><strong>New UniFi IP list:</strong> {{ implode(', ', $list->new_parts) }}. The list grew past {{ number_format(app(\App\Services\Policy\FirewallListPusher::class)->groupLimit()) }} addresses per UniFi list, so it has a new part. Your firewall rules in UniFi don't use it yet, so its addresses aren't {{ $list->isAllow() ? 'let through' : 'blocked' }} until you add it to them.
        <form method="post" action="{{ route('admin.policy.blocklists.firewall.parts-added', $list) }}" class="button-row">@csrf<button class="button small secondary">I've added it to my rules</button></form>
    </div>
@endif
@if(! $managesRules && $list->rules)
    <p class="hint"><strong>Made by Filterdesk earlier, now yours to manage.</strong> Filterdesk no longer changes these; keep or remove them in UniFi.</p>
    <x-firewall-rules :rules="$details" />
@endif
