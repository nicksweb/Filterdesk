@props(['lookup' => null, 'domain' => null, 'showPolicies' => false])
{{-- Website categories from the lookup service, with matching UniFi categories. A guide only. --}}
@if($lookup)
    <div class="site-categories">
        @if(!($lookup['ok'] ?? false))
            <small class="hint">Category unavailable: {{ $lookup['message'] ?? 'lookup failed' }}</small>
        @elseif(empty($lookup['categories']) && empty($lookup['risks']))
            <small class="hint">{{ $domain ? $domain.': ' : '' }}{{ $lookup['message'] ?? 'Not categorised yet.' }}</small>
        @else
            <span class="category-label">{{ $domain ? $domain.' is' : 'Category' }}</span>
            @foreach($lookup['categories'] as $category)<span class="category-chip">{{ $category }}</span>@endforeach
            @foreach($lookup['risks'] ?? [] as $risk)<span class="category-chip risk">{{ $risk }}</span>@endforeach
            @if($showPolicies && !empty($lookup['unifi']))
                <small class="category-unifi">UniFi category:
                    @foreach($lookup['unifi'] as $match){{ $match['label'] }} ({{ $match['blocked_by'] ? 'blocked by '.implode(', ', $match['blocked_by']) : 'not blocked by any linked filter' }})@if(!$loop->last); @endif @endforeach
                </small>
            @endif
        @endif
    </div>
@endif
