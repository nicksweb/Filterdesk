{{-- The signed-out check: a box, one plain line per network type, and a way to ask for access. --}}
<section class="panel check-form public-check">
    <form method="post" action="{{ $embed ? route('checks.embed.store') : route('checks.public.store') }}">@csrf
        <label><span>Website <span class="optional">up to {{ \App\Http\Controllers\PublicCheckController::MAX_SITES }}</span></span>
            <input name="websites" required value="{{ old('websites', $websites) }}" placeholder="example.com">
            @error('websites')<small class="field-error">{{ $message }}</small>@enderror</label>
        <x-privacy-note>The websites you check are recorded.</x-privacy-note>
        <div class="form-actions"><button class="button primary">Check</button></div>
    </form>
</section>
@if($results !== null)
    @foreach($results as $host => $types)
        <section class="panel site-result">
            <h2>{{ $host }}</h2>
            <ul class="simple-results">
                @forelse($types as $type => $state)
                    <li><span><strong>{{ $type }}</strong></span><span><span class="probe-state {{ $state }}">{{ ['allowed' => 'Allowed', 'blocked' => 'Blocked', 'unknown' => "Couldn't check"][$state] }}</span></span></li>
                @empty
                    <li><span>Couldn't check right now. Try again in a minute.</span></li>
                @endforelse
            </ul>
            @if(in_array('blocked', $types, true) || in_array('unknown', $types, true))
                <p><a class="button secondary small" href="{{ route('requests.create', ['domains' => $host]) }}" @if($embed) target="_top" @endif>Sign in to request access</a></p>
            @endif
        </section>
    @endforeach
@endif
