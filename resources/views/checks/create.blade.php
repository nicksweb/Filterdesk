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
                <textarea name="websites" rows="5" required placeholder="example.com&#10;https://learning.example.org/resource">{{ old('websites', request('websites')) }}</textarea>
            </label>
            <div class="check-submit-row">
                <p>Each run starts a fresh browser with an empty DNS cache, so re-running after a filter change shows the current result.</p>
                <button class="button primary">Run checks</button>
            </div>
        </form>
    </section>

    @isset($checks)
        <form method="post" action="{{ route('checks.store') }}" class="rerun-form">
            @csrf
            <input type="hidden" name="websites" value="{{ collect($checks)->pluck('url')->implode(",") }}">
            <button class="button secondary small" type="submit">Re-run these checks with fresh DNS</button>
        </form>
        <form method="get" action="{{ route('requests.create') }}" class="batch-request-form">
            <div class="result-heading">
                <div>
                    <h2>Check results</h2>
                    <p>{{ count($checks) }} {{ str('website')->plural(count($checks)) }} checked in {{ $duration }} ms</p>
                </div>
            </div>

            @foreach($checks as $check)
                @php($isBlocked = collect($check['results'])->contains(fn ($result) => in_array($result['status'] ?? '', ['blocked', 'dns_failed'], true)))
                <section class="site-result">
                    <div class="site-result-heading">
                        <span class="site-domain"><strong>{{ $check['hostname'] }}</strong><small>{{ $check['url'] }}</small></span>
                        @if($isBlocked)<span class="selection-hint blocked">Blocked · added to shortlist</span>@else<span>{{ $check['duration_ms'] }} ms</span>@endif
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
                                @if(!empty($result['block_method']))<p class="probe-message">Blocked at the {{ $result['block_method'] === 'dns' ? 'DNS level (sinkhole answer)' : 'block page' }}.</p>@endif
                                @php($failedHosts = collect($result['hosts'] ?? [])->where('failed', true)->pluck('host')->reject(fn ($host) => $host === $check['hostname']))
                                @if($failedHosts->isNotEmpty())<p class="probe-message">Also failed to load: {{ $failedHosts->take(8)->implode(', ') }}{{ $failedHosts->count() > 8 ? ' …' : '' }}</p>@endif
                                @if(!empty($result['message']))<p class="probe-message">{{ $result['message'] }}</p>@endif
                                @if(!empty($result['page_title']))<p><strong>Page title:</strong> {{ $result['page_title'] }}</p>@endif
                            </article>
                        @endforeach
                    </div>
                </section>
            @endforeach

            <section class="panel request-shortlist">
                <div class="panel-heading"><div><h2>Build access request</h2><p>Select every domain to include. Domains detected as blocked are selected automatically.</p></div></div>
                <div class="shortlist-grid">
                    @foreach($checks as $check)
                        @php($isBlocked = collect($check['results'])->contains(fn ($result) => in_array($result['status'] ?? '', ['blocked', 'dns_failed'], true)))
                        <label class="choice shortlist-option">
                            <input type="checkbox" name="domains[]" value="{{ $check['hostname'] }}" @checked($isBlocked)>
                            <span><strong>{{ $check['hostname'] }}</strong><small>{{ $isBlocked ? 'Blocked on at least one network — automatically selected' : 'Not identified as blocked — select to include' }}</small></span>
                        </label>
                    @endforeach
                </div>
            </section>

            <div class="batch-request-bar">
                <p>Selected domains will be combined into one access request.</p>
                <button class="button primary" type="submit">Continue to access request</button>
            </div>
        </form>
    @endisset
</x-layouts.app>
