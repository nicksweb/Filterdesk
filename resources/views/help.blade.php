<x-layouts.app title="How to">
    @php($support = app(\App\Services\Support::class)->all())
    @php($guide = app(\App\Services\Requests\AccessGuide::class))
    <div class="page-heading"><div><p class="eyebrow">Help</p><h1>Checking websites and asking for access</h1>
        <p>How the school's web filter works, and what to do when a website is blocked.</p></div>
        @if($support['article_url'] !== '')<a class="button secondary" href="{{ $support['article_url'] }}" target="_blank" rel="noopener">{{ $support['article_label'] ?: 'Support article' }}</a>@endif
    </div>

    <div class="content-grid">
        <div class="stack help-page">
            <section class="panel">
                <h2>1. Check a website</h2>
                <ol>
                    <li>Open <a href="{{ route('checks.create') }}">Check websites</a> and type the website address, for example <code>example.com</code>. You can check up to 10 at once, one per line.</li>
                    <li>Choose <strong>Run checks</strong>. The answer shows what the school network allows right now, on {{ auth()->user()->isStudent() ? 'the student network' : 'each school network' }}.</li>
                </ol>
            </section>
            <section class="panel">
                <h2>2. What the answer means</h2>
                <ul>
                    <li><strong>Allowed:</strong> the website works at school. If it doesn't open for you, see <em>Still blocked after it was allowed?</em> below.</li>
                    <li><strong>Blocked:</strong> the school filter stops it. Some websites are only blocked <strong>in school hours</strong>, and the answer says so.</li>
                    <li><strong>Couldn't check right now:</strong> try again in a minute. If it keeps happening, contact IT.</li>
                    <li><strong>Works, but needs the school certificate:</strong> your device needs the school's security certificate. <a href="{{ route('certificate.show') }}">Install it here</a>.</li>
                </ul>
            </section>
            @unless(auth()->user()->isStudent() && ! app(\App\Services\SettingsRepository::class)->get('approval.students_can_request', false))
            <section class="panel">
                <h2>3. Ask for access, or for a block</h2>
                <ol>
                    <li>After a check, a blocked website is already ticked. Choose <strong>Request access</strong> (or <strong>Request a block</strong> for a website that should be blocked).</li>
                    <li>Say why it's needed and for whom, then send it. IT is told straight away.</li>
                    <li>Follow it under <a href="{{ route('requests.index') }}">Requests</a>. You're emailed when IT decides or asks a question.</li>
                </ol>
            </section>
            @endunless
            <section class="panel">
                <h2>Still blocked after it was allowed?</h2>
                <p>{{ $guide->settings()['intro'] }}</p>
                <ol>@foreach($guide->steps() as $step)<li>{{ $step }}</li>@endforeach</ol>
                <p class="hint">A private (incognito) window doesn't use the browser's saved answers, so it's a quick way to try the site again: Ctrl+Shift+N in Chrome and Edge, Ctrl+Shift+P in Firefox, or Cmd+Shift+N in Safari.</p>
            </section>
        </div>
        <x-help-box />
    </div>
</x-layouts.app>
