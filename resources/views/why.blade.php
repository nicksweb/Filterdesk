<x-layouts.app title="Why is this blocked?">
    <div class="page-heading">
        <div>
            <p class="eyebrow">Help</p>
            <h1>Why is this blocked?</h1>
            <p>Type a website to see whether the school network allows it, and ask for access if you need it.</p>
        </div>
    </div>
    <form method="get" class="panel search-line">
        <label><span>Website</span><input name="site" value="{{ $site }}" placeholder="example.com" required autofocus></label>
        <button class="button primary">Check</button>
    </form>
    @if($site)
        <section class="panel" style="margin-top:20px">
            <h2>{{ $site }}</h2>
            <ul class="plain-list">
                @foreach($answers as $answer)
                    <li><strong>{{ $answer['type']->name }} network:</strong> <span class="verdict verdict-{{ $answer['verdict'] }}">{{ $answer['verdict'] === 'unknown' ? 'Not filtered here' : ucfirst($answer['verdict']) }}</span><small>{{ $answer['reason'] }}</small></li>
                @endforeach
            </ul>
            @if($answers->contains('verdict', 'blocked') && (! auth()->user()->isStudent() || app(\App\Services\SettingsRepository::class)->get('approval.students_can_request', false)))
                <div class="form-actions"><a class="button primary" href="{{ route('requests.create', ['domains' => $site]) }}">Ask for access</a></div>
            @endif
            <p class="hint">This is what the school's filter is set to do. A website check shows what each network actually does: <a href="{{ route('checks.create', ['websites' => $site]) }}">check it now</a>.</p>
        </section>
    @endif
    <section class="panel" style="margin-top:20px">
        <h2>Ask from any blocked page</h2>
        <p class="hint">Drag this link to your bookmarks bar. On a page that will not open, click it to come straight here with the site filled in.</p>
        <p><a class="button secondary" href="javascript:location.href='{{ route('why') }}?site='+encodeURIComponent(location.hostname)">Is this blocked?</a></p>
    </section>
</x-layouts.app>
