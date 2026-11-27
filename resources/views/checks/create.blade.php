<x-layouts.app title="Check websites">
    <x-slot:head><link rel="stylesheet" href="{{ asset('check-flow.css') }}"></x-slot:head>

    <div class="page-heading">
        <div>
            <p class="eyebrow">Website check</p>
            <h1>Check websites</h1>
            <p>{{ auth()->user()->isIt() ? 'Compare access on each network, capture evidence, then raise one request for every selected domain.' : app(\App\Services\Wording::class)->get(auth()->user()->isStudent() ? 'check_intro_student' : 'check_intro_staff') }}</p>
        </div>
    </div>

    @if($here ?? null)
        <p class="notice here-notice">You're on <strong>{{ $here['network']->name }}</strong>{{ $here['network']->vlan ? ' (VLAN '.$here['network']->vlan.')' : '' }}, which gets <strong>{{ $here['type'] ? \App\Models\NetworkType::labelFor($here['type']).' filtering' : 'no managed filtering' }}</strong>{{ auth()->user()->isIt() && $here['policy'] ? ' from '.$here['policy']->name : '' }}.@if($here['profile'] && !auth()->user()->isStudent()) Its result is shown first.@endif</p>
    @endif

    <section class="panel check-form">
        <form method="post" action="{{ route('checks.store') }}">
            @csrf
            <label>
                <span>Website URLs or domains <span class="optional">up to 10, one per line</span></span>
                <textarea name="websites" rows="5" required placeholder="example.com&#10;https://learning.example.org/resource">{{ old('websites', request('websites')) }}</textarea>
            </label>
            <div class="check-submit-row">
                @if(auth()->user()->isIt())<small class="hint" title="Each run starts a fresh browser with an empty DNS cache, so re-running after a filter change shows the current result.">Fresh browser and DNS on every run.</small>@else<p>{{ app(\App\Services\Wording::class)->get('check_hint') }}</p>@endif
                <button class="button primary">Run checks</button>
            </div>
        </form>
    </section>

    @isset($checks)
        @php($isIt = auth()->user()->isIt())
        @if($isIt)
        <form method="post" action="{{ route('checks.store') }}" class="rerun-form">
            @csrf
            <input type="hidden" name="websites" value="{{ collect($checks)->pluck('url')->implode(",") }}">
            <button class="button secondary small" type="submit">Re-run these checks with fresh DNS</button>
            <a class="button secondary small" href="{{ route('admin.policy.quick.show', ['domains' => collect($checks)->pluck('hostname')->implode("\n")]) }}">Quick block or allow…</a>
        </form>
        @endif
        <form method="get" action="{{ route('requests.create') }}" class="batch-request-form">
            <div class="result-heading">
                <div>
                    <h2>Check results</h2>
                    <p>{{ count($checks) }} {{ str('website')->plural(count($checks)) }} checked{{ $isIt ? " in {$duration} ms" : '' }}</p>
                </div>
            </div>

            @foreach($checks as $check)
                @php($isBlocked = collect($check['results'])->contains(fn ($result) => in_array($result['status'] ?? '', ['blocked', 'dns_failed', 'unreachable'], true)))
                <section class="site-result">
                    <div class="site-result-heading">
                        <span class="site-domain"><strong>{{ $check['hostname'] }}</strong><small>{{ $check['url'] }}</small></span>
                        @if($isBlocked)<span class="selection-hint blocked">Blocked · added to shortlist</span>@elseif($isIt)<span>{{ $check['duration_ms'] }} ms</span>@endif
                    </div>
                    <x-site-categories :lookup="$categories[$check['hostname']] ?? null" :show-policies="auth()->user()->isIt()" />

                    @unless($isIt)
                        {{-- Students and staff: one line per network, no addresses, timings or probe messages. --}}
                        <ul class="simple-results">
                            @foreach($check['results'] as $key => $result)
                                @php($status = $result['status'] ?? 'unknown')
                                @php($certificate = $status === 'tls_error' && app(\App\Services\Network\InspectionCertificate::class)->matches($result['tls_issuer'] ?? null))
                                {{-- One plain answer: no certificate, DNS or probe details (IT sees those in the request and their own view). --}}
                                @php([$label, $tone] = match (true) { in_array($status, ['blocked', 'dns_failed', 'unreachable'], true) => ['Blocked', 'blocked'], $status === 'allowed' || $certificate => ['Allowed', 'allowed'], default => ["Couldn't check", 'unknown'] })
                                @php($when = $schoolHoursOnly[$check['hostname']][$key] ?? null)
                                <li><span><strong>{{ $result['profile'] }}</strong>@if(($here['profile'] ?? null)?->key === $key)<small>Your network</small>@endif</span>
                                    <span><span class="probe-state {{ $tone }}">{{ $label }}</span>@if($when && $tone === 'blocked') <small>in school hours ({{ $when }}), open outside them</small>@endif
                                    </span>
                                    @if(($previews ?? false) && !empty($result['screenshot']))<figure class="probe-screenshot"><img src="data:image/jpeg;base64,{{ $result['screenshot'] }}" alt="Screenshot of {{ $check['hostname'] }} from the {{ $result['profile'] }} network"></figure>@endif</li>
                            @endforeach
                        </ul>
                    @else
                    <div class="result-grid">
                        @foreach($check['results'] as $key => $result)
                            <article class="result-card">
                                <div class="result-top">
                                    <span><strong>{{ $result['profile'] }}</strong><small>{{ ($here['profile'] ?? null)?->key === $key ? 'Your network' : ucfirst($key).' network probe' }}</small></span>
                                    <span class="probe-state {{ $result['status'] ?? 'unknown' }}">{{ str($result['status'] ?? 'unknown')->replace('_', ' ')->title() }}</span>
                                </div>
                                @if(($result['block_method'] ?? null) === 'dns')
                                    <div class="no-shot dns-blocked"><strong>Blocked by DNS</strong> on {{ $result['profile'] }}@if(!empty($result['addresses']) && auth()->user()->isIt()): {{ implode(', ', $result['addresses']) }}@endif<small>Nothing loaded, so there is no screenshot.</small></div>
                                @elseif(!empty($result['dns_only']) && ($result['status'] ?? null) === 'allowed')
                                    <div class="no-shot"><strong>Resolves normally</strong> on {{ $result['profile'] }}@if(!empty($result['addresses']) && auth()->user()->isIt()): {{ implode(', ', $result['addresses']) }}@endif<small>DNS-only check: the page was not loaded.</small></div>
                                @elseif(!empty($result['screenshot']))
                                    <figure class="probe-screenshot">
                                        <img src="data:image/jpeg;base64,{{ $result['screenshot'] }}" alt="Screenshot of {{ $check['hostname'] }} from the {{ $result['profile'] }} network">
                                        <figcaption>Screenshot from {{ $result['profile'] }}</figcaption>
                                    </figure>
                                @elseif($previews ?? false)
                                    <div class="no-shot">
                                        Screenshot unavailable
                                        @if(!empty($result['screenshot_error'])) — {{ $result['screenshot_error'] }}; the result above still stands.
                                        @elseif(!empty($result['message'])) — {{ $result['message'] }} @endif
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
                                @if($finding = $firewallFindings[$check['hostname']][$key] ?? null)<p @class(['probe-message', 'firewall-finding', 'blocked' => $finding['blocked']])>{{ $finding['text'] }} @if($finding['blocked'])<a href="{{ route('admin.policy.quick.show', ['domains' => $check['hostname'], 'kind' => 'allow']) }}">Quick allow…</a>@endif</p>@endif
                                @if(!empty($result['tls_error']) && !empty($result['tls_issuer']))
                                    @if(app(\App\Services\Network\InspectionCertificate::class)->matches($result['tls_issuer']))
                                        <p class="probe-message">This network inspects HTTPS with the school's certificate ({{ $result['tls_issuer'] }}). Devices need it installed: <a href="{{ route('certificate.show') }}">install the school certificate</a>.</p>
                                    @else
                                        <p class="probe-message">Certificate issued by {{ $result['tls_issuer'] }}.</p>
                                    @endif
                                @endif
                                @if(!empty($result['page_title']))<p><strong>Page title:</strong> {{ $result['page_title'] }}</p>@endif
                                @if($when = $schoolHoursOnly[$check['hostname']][$key] ?? null)<p class="probe-message">Blocked only by the school-hours filter ({{ $when }}).</p>@endif
                            </article>
                        @endforeach
                    </div>
                    @endunless
                </section>
            @endforeach

            <section class="panel request-shortlist">
                <div class="panel-heading"><div>@if($isIt)<h2>Ask IT to allow or block</h2><p>Select the domains to include. Domains detected as blocked are selected automatically for an access request; for a block request, select the ones that should be blocked.</p>@else<h2>Ask IT</h2><p>Tick the websites you need, then press Request access.</p>@endif</div></div>
                <div class="shortlist-grid">
                    @foreach($checks as $check)
                        @php($isBlocked = collect($check['results'])->contains(fn ($result) => in_array($result['status'] ?? '', ['blocked', 'dns_failed', 'unreachable'], true)))
                        <label class="choice shortlist-option">
                            <input type="checkbox" name="domains[]" value="{{ $check['hostname'] }}" @checked($isBlocked)>
                            <span><strong>{{ $check['hostname'] }}</strong><small>{{ $isBlocked ? ($isIt ? 'Blocked on at least one network — automatically selected' : 'Blocked') : ($isIt ? 'Not identified as blocked — select to include' : 'Allowed') }}</small></span>
                        </label>
                    @endforeach
                </div>
            </section>

            <div class="batch-request-bar">
                @if($isIt)<p>Selected domains are combined into one request.</p>@else<p></p>@endif
                <div class="button-row">
                    @unless(auth()->user()->isStudent())<button class="button secondary" type="submit" name="type" value="block">Request a block</button>@endunless
                    <button class="button primary" type="submit">Request access</button>
                </div>
            </div>
        </form>
    @endisset
    @unless(auth()->user()->isIt())<div class="help-row"><x-help-box /></div>@endunless
</x-layouts.app>
