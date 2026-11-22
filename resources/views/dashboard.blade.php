<x-layouts.app title="Overview">
    <div class="page-heading"><div><p class="eyebrow">School IT self-service</p><h1>Good {{ now()->hour < 12 ? 'morning' : (now()->hour < 17 ? 'afternoon' : 'evening') }}, {{ str(auth()->user()->name)->before(' ') }}</h1><p>Check website access or follow a request from submission to resolution.</p></div>@unless(auth()->user()->isStudent())<a class="button primary" href="{{ route('requests.create') }}">Request website access</a>@endunless</div>
    @if($needsControllerSetup)
        <div class="alert success setup-callout"><strong>Next: bring your UniFi setup in.</strong> The controller is connected but none of its content filters are in the tool yet. <a class="button small primary" href="{{ route('admin.policy.setup.show') }}">Open guided setup</a> to import every filter, list and network in one step, then set up the probes.</div>
    @endif
    @if($probesNeedingWork->isNotEmpty())
        <div class="alert error probe-callout" role="alert"><strong>Probes need finishing:</strong>
            @foreach($probesNeedingWork as $item){{ $item['profile']->name }} ({{ $item['diagnosis']['summary'] ?? 'no network chosen' }})@if(!$loop->last); @endif @endforeach
            <a class="button small secondary" href="{{ route('admin.settings.show', ['section' => 'profiles', 'refresh' => 1]) }}">Open the probe checklists</a>
        </div>
    @endif
    @if($filtersOutOfSync->isNotEmpty())
        <div class="alert error" role="alert"><strong>Web filters need attention:</strong>
            @foreach($filtersOutOfSync as $policy)<a href="{{ route('admin.policy.policies.show', $policy) }}">{{ $policy->name }}</a> ({{ $policy->sync_details['summary'] ?? $policy->sync_status }})@if(!$loop->last); @endif @endforeach
        </div>
    @endif
    <section class="stat-grid">
        @if(auth()->user()->isIt())
            <a class="stat-link" href="{{ route('requests.index', ['status' => 'open']) }}"><article><span>Awaiting decision</span><strong>{{ $pending }}</strong><small>{{ $mine }} assigned to you</small></article></a>
            <a class="stat-link" href="{{ route('requests.index', ['status' => 'action_required']) }}"><article><span>Approved, to apply</span><strong>{{ $actionRequired }}</strong><small>Copy into the controller from Policy lists</small></article></a>
        @else
            <article><span>Open requests</span><strong>{{ $pending }}</strong><small>Waiting on a person or action</small></article>
            <article><span>Active allowances</span><strong>{{ $active }}</strong><small>Approved website access</small></article>
        @endif
        <a class="quick-card" href="{{ route('checks.create') }}"><span class="quick-icon">⌕</span><span><strong>Check a website</strong><small>Compare staff and student access in seconds</small></span></a></section>
    <div class="content-grid"><section class="panel"><div class="panel-heading"><div><h2>Recent requests</h2><p>Latest access decisions and progress</p></div><a href="{{ route('requests.index') }}">View all</a></div>
        @forelse($recentRequests as $item)<a class="list-row" href="{{ route('requests.show', $item) }}"><span><strong>{{ implode(', ', array_slice($item->domains, 0, 2)) }}</strong><small>{{ $item->reference }} · {{ $item->requester->name }}</small></span><x-status :status="$item->status" /></a>@empty<p class="empty">No website requests yet.</p>@endforelse
    </section><section class="panel"><div class="panel-heading"><div><h2>Your recent checks</h2><p>Results are retained for audit only</p></div></div>
        @forelse($recentChecks as $check)<div class="list-row static"><span><strong>{{ $check->hostname }}</strong><small>{{ $check->created_at->diffForHumans() }}</small></span><span class="duration">{{ $check->duration_ms }} ms</span></div>@empty<p class="empty">Run your first website check.</p>@endforelse
    </section></div>
</x-layouts.app>
