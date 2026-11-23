@props(['coverage', 'compact' => false])
<section class="panel coverage">
    <div class="panel-heading">
        <div><h2>Coverage <span class="coverage-score score-{{ $coverage['score'] === null ? 'unknown' : ($coverage['score'] >= 90 ? 'good' : ($coverage['score'] >= 60 ? 'fair' : 'poor')) }}">{{ $coverage['score'] === null ? '—' : $coverage['score'].'%' }}</span></h2><p>How completely the policy is in force, from what this app knows.</p></div>
        @if($compact)<a href="{{ route('admin.health') }}">Details</a>@endif
    </div>
    <ul class="coverage-parts">
        @foreach($coverage['parts'] as $part)
            <li class="part-{{ $part['ok'] === null ? 'unknown' : ($part['ok'] ? 'ok' : 'bad') }}"><a href="{{ $part['link'] }}"><strong>{{ $part['label'] }}</strong> <span>{{ $part['value'] }}</span></a>@unless($compact)<small>{{ $part['detail'] }}</small>@endunless</li>
        @endforeach
    </ul>
</section>
