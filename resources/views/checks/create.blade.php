<x-layouts.app title="Check websites">
    <x-slot:head><link rel="stylesheet" href="{{ asset('check-flow.css') }}"></x-slot:head>

    <div class="page-heading">
        <div>
            <p class="eyebrow">Network visibility</p>
            <h1>Check websites</h1>
            <p>Compare Staff and Student access, capture evidence, then raise one request for every selected domain.</p>
        </div>
    </div>

    <section class="panel check-form">
        <form method="post" action="{{ route('checks.store') }}">
            @csrf
            <label>
                <span>Website URLs or domains <span class="optional">up to 10, one per line</span></span>
                <textarea name="websites" rows="5" required placeholder="example.com&#10;https://learning.example.org/resource">{{ old('websites') }}</textarea>
            </label>
            <div class="check-submit-row">
                <p>Screenshots are captured automatically from both network paths.</p>
                <button class="button primary">Run checks</button>
            </div>
        </form>
    </section>

    @isset($checks)
        <form method="get" action="{{ route('requests.create') }}" class="batch-request-form">
            <div class="result-heading">
                <div>
                    <h2>Check results</h2>
                    <p>{{ count($checks) }} {{ str('website')->plural(count($checks)) }} checked in {{ $duration }} ms</p>
                </div>
                <button class="button primary" type="submit">Request access for selected</button>
            </div>

            @foreach($checks as $check)
                @php($needsAccess = collect($check['results'])->contains(fn ($result) => ($result['status'] ?? '') !== 'allowed'))
                <section class="site-result">
                    <div class="site-result-heading">
                        <label class="site-selector">
                            <input type="checkbox" name="domains[]" value="{{ $check['hostname'] }}" @checked($needsAccess)>
                            <span><strong>{{ $check['hostname'] }}</strong><small>{{ $check['url'] }}</small></span>
                        </label>
                        <span>{{ $check['duration_ms'] }} ms</span>
                    </div>

                    <div class="result-grid">
                        @foreach($check['results'] as $key => $result)
                            <article class="result-card">
                                <div class="result-top">
                                    <span><strong>{{ $result['profile'] }}</strong><small>{{ ucfirst($key) }} network probe</small></span>
                                    <span class="probe-state {{ $result['status'] ?? 'unknown' }}">{{ str($result['status'] ?? 'unknown')->replace('_', ' ')->title() }}</span>
                                </div>
                                @if(!empty($result['screenshot']))
                                    <figure class="probe-screenshot">
                                        <img src="data:image/jpeg;base64,{{ $result['screenshot'] }}" alt="Screenshot of {{ $check['hostname'] }} from the {{ $result['profile'] }} network">
                                        <figcaption>Screenshot from {{ $result['profile'] }}</figcaption>
                                    </figure>
                                @else
                                    <div class="no-shot">
                                        Screenshot unavailable
                                        @if(!empty($result['message'])) — {{ $result['message'] }} @endif
                                    </div>
                                @endif
                                <div class="result-meta">
                                    <span>HTTP <strong>{{ $result['http_status'] ?? '—' }}</strong></span>
                                    <span>DNS <strong>{{ implode(', ', $result['addresses'] ?? []) ?: '—' }}</strong></span>
                                    <span>Time <strong>{{ $result['duration_ms'] ?? '—' }} ms</strong></span>
                                </div>
                                @if(!empty($result['message']))<p class="probe-message">{{ $result['message'] }}</p>@endif
                                @if(!empty($result['page_title']))<p><strong>Page title:</strong> {{ $result['page_title'] }}</p>@endif
                            </article>
                        @endforeach
                    </div>
                </section>
            @endforeach

            <div class="batch-request-bar">
                <p>Selected domains will be combined into one access request.</p>
                <button class="button primary" type="submit">Continue to access request</button>
            </div>
        </form>
    @endisset
</x-layouts.app>
