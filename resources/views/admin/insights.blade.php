<x-layouts.app title="Insights">
    <x-slot:head><link rel="stylesheet" href="{{ asset('admin.css') }}"></x-slot:head>
    <div class="page-heading">
        <div>
            <p class="eyebrow">{{ $school ?: 'Report' }}</p>
            <h1>Web filtering report: {{ $label }}</h1>
            <p>{{ $report['from']->local()->format('j M Y') }} – {{ $report['to']->local()->format('j M Y') }} · requests, decisions, policy versions and exception reviews. No browsing data is used.</p>
        </div>
        <div class="button-row no-print">
            <a class="button secondary" href="{{ route('admin.insights.csv', request()->only('from', 'to')) }}">Download CSV</a>
            <button class="button secondary" type="button" data-print>Print or save as PDF</button>
        </div>
    </div>
    <form method="get" class="filter-bar no-print">
        <label>From<input type="date" name="from" value="{{ $report['from']->format('Y-m-d') }}"></label>
        <label>To<input type="date" name="to" value="{{ $report['to']->format('Y-m-d') }}"></label>
        <button class="button secondary">Change period</button>
    </form>

    <div class="stat-grid four">
        <article><span>Requests</span><strong>{{ $report['submitted'] }}</strong><small>{{ $report['approved'] }} approved · {{ $report['declined'] }} declined · {{ $report['open'] }} open</small></article>
        <article><span>Median time to decide</span><strong>{{ $report['median_hours'] === null ? '—' : ($report['median_hours'] < 48 ? $report['median_hours'].' h' : round($report['median_hours'] / 24, 1).' days') }}</strong><small>From submission to approval or decline</small></article>
        <article><span>Policy versions</span><strong>{{ $report['versions'] }}</strong><small>{{ $report['rollbacks'] }} rollback(s)</small></article>
        <article><span>Exception reviews</span><strong>{{ $report['kept'] }}</strong><small>kept · {{ $report['expired_unreviewed'] }} removed unreviewed · {{ number_format($report['exceptions']) }} exceptions in place</small></article>
    </div>

    <section class="panel policy-section">
        <h2>Suggestions</h2>
        @forelse($report['insights'] as $insight)
            <div class="insight"><strong>{{ $insight['title'] }}</strong><p>{{ $insight['detail'] }}</p>@if($insight['link'])<a class="no-print" href="{{ $insight['link'] }}">{{ $insight['action'] }}</a>@endif</div>
        @empty
            <p class="empty">No patterns stand out for this period.</p>
        @endforelse
    </section>

    <div class="content-grid">
        <section class="panel policy-section">
            <h2>Requests by category</h2>
            <table class="report-table">@forelse($report['by_category'] as $category => $count)<tr><td>{{ $category ?: 'None' }}</td><td>{{ $count }}</td></tr>@empty<tr><td class="empty">No requests.</td></tr>@endforelse</table>
            @if($report['by_department'])<h2>Requests by department</h2>
            <table class="report-table">@foreach($report['by_department'] as $department => $count)<tr><td>{{ $department }}</td><td>{{ $count }}</td></tr>@endforeach</table>@endif
            <h2>Approvals by network type</h2>
            <table class="report-table">@forelse($report['by_type'] as $type => $count)<tr><td>{{ $type }}</td><td>{{ $count }}</td></tr>@empty<tr><td class="empty">No approvals.</td></tr>@endforelse</table>
        </section>
        <section class="panel policy-section">
            <h2>Decline reasons</h2>
            <table class="report-table">@forelse($report['decline_reasons'] as $reason => $count)<tr><td>{{ $reason ?: 'No reason given' }}</td><td>{{ $count }}</td></tr>@empty<tr><td class="empty">None declined.</td></tr>@endforelse</table>
            <h2>Coverage gaps</h2>
            @if($report['unfiltered_networks']->isEmpty())<p class="hint">Every mapped network has a filter policy.</p>@else<p>Networks with no filter policy: @foreach($report['unfiltered_networks'] as $network){{ $network->name }}{{ $network->vlan ? ' (VLAN '.$network->vlan.')' : '' }}@if(!$loop->last), @endif @endforeach</p>@endif
        </section>
    </div>
</x-layouts.app>
