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
    @if($setup)
        <section class="panel setup-checklist">
            <div class="panel-heading"><div><h2>Finish setting up</h2><p>{{ collect($setup)->where('done', true)->count() }} of {{ count($setup) }} done</p></div>
                <form method="post" action="{{ route('setup.done') }}">@csrf<input type="hidden" name="item" value="all"><button class="link-button">Hide this list</button></form></div>
            <ol class="setup-items">
                @foreach($setup as $item)
                    <li @class(['done' => $item['done']])><span class="check-icon {{ $item['done'] ? 'ok' : '' }}">{{ $item['done'] ? '✓' : $loop->iteration }}</span>
                        <span><a href="{{ $item['link'] }}"><strong>{{ $item['label'] }}</strong></a>@if($item['optional']) <span class="optional">optional</span>@endif<small>{{ $item['detail'] }}</small></span>
                        @unless($item['done'])<form method="post" action="{{ route('setup.done') }}">@csrf<input type="hidden" name="item" value="{{ $item['key'] }}"><button class="link-button">Mark done</button></form>@endunless
                    </li>
                @endforeach
            </ol>
        </section>
    @endif
    @if($myReviews)
        <div class="alert">You own {{ $myReviews }} website exception(s) due for review. <a href="{{ route('reviews.index') }}">Review them</a> so they are not removed.</div>
    @endif
    @if($secondApprovals)
        <div class="alert">{{ $secondApprovals }} change(s) wait for a second IT officer. <a href="{{ route('admin.policy.changes.index') }}">Open Changes</a></div>
    @endif
    @if($coverage)
        <div class="content-grid home-manager">
            <x-coverage :coverage="$coverage" compact />
            <section class="panel"><div class="panel-heading"><div><h2>Suggestions</h2><p>From this term's requests</p></div><a href="{{ route('admin.insights') }}">Insights</a></div>
                @forelse($insights as $insight)<div class="insight"><strong>{{ $insight['title'] }}</strong>@if($insight['link'])<a href="{{ $insight['link'] }}">{{ $insight['action'] }}</a>@endif</div>@empty<p class="empty">Nothing stands out yet.</p>@endforelse
            </section>
        </div>
    @endif
    <section class="stat-grid">
        @if(auth()->user()->isIt())
            <a class="stat-link" href="{{ route('requests.index', ['status' => 'open']) }}"><article><span>Awaiting decision</span><strong>{{ $pending }}</strong><small>{{ $mine }} assigned to you</small></article></a>
            <a class="stat-link" href="{{ route('requests.index', ['status' => 'action_required']) }}"><article><span>Approved, to apply</span><strong>{{ $actionRequired }}</strong><small>Publish them under Changes</small></article></a>
        @else
            <article><span>Open requests</span><strong>{{ $pending }}</strong><small>Waiting on a person or action</small></article>
            <article><span>Active allowances</span><strong>{{ $active }}</strong><small>Approved website access</small></article>
        @endif
        <a class="quick-card" href="{{ route('checks.create') }}"><span class="quick-icon">⌕</span><span><strong>Check a website</strong><small>{{ auth()->user()->isStudent() ? 'See if a site opens on your network' : 'Compare access on each network in seconds' }}</small></span></a></section>
    <div class="content-grid"><section class="panel"><div class="panel-heading"><div><h2>Recent requests</h2><p>Latest access decisions and progress</p></div><a href="{{ route('requests.index') }}">View all</a></div>
        @forelse($recentRequests as $item)<a class="list-row" href="{{ route('requests.show', $item) }}"><span><strong>{{ implode(', ', array_slice($item->domains, 0, 2)) }}</strong><small>{{ $item->reference }} · {{ $item->requester->name }}</small></span><x-status :status="$item->status" /></a>@empty<p class="empty">No website requests yet.</p>@endforelse
    </section>@if($publishedToday->isNotEmpty())<section class="panel"><div class="panel-heading"><div><h2>Published today</h2><p>Policy versions written to the controller</p></div><a href="{{ route('admin.policy.changes.index') }}">Changes</a></div>
        @foreach($publishedToday as $release)<a class="list-row" href="{{ route('admin.policy.versions.show', $release) }}"><span><strong>{{ $release->label() }}</strong><small>{{ $release->triggerLabel() }} · {{ $release->summary }}</small></span><span class="duration">{{ $release->created_at->local()->format('g:i a') }}</span></a>@endforeach
    </section>@else<section class="panel"><div class="panel-heading"><div><h2>Your recent checks</h2><p>Results are retained for audit only</p></div></div>
        @forelse($recentChecks as $check)<div class="list-row static"><span><strong>{{ $check->hostname }}</strong><small>{{ $check->created_at->diffForHumans() }}</small></span><span class="duration">{{ $check->duration_ms }} ms</span></div>@empty<p class="empty">Run your first website check.</p>@endforelse
        @unless(auth()->user()->isIt())<p class="hint">Something will not open? <a href="{{ route('why') }}">Find out why it is blocked</a>.</p>@endunless
    </section>@endif</div>
</x-layouts.app>
