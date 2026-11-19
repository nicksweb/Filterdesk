<x-layouts.app title="Policy lists">
    <x-slot:head><link rel="stylesheet" href="{{ asset('admin.css') }}"></x-slot:head>

    <div class="page-heading">
        <div><p class="eyebrow">Administration</p><h1>UniFi policy reconciliation</h1><p>Combine the managed policy and approved requests, then compare the result with the lists currently in UniFi.</p></div>
    </div>

    <div class="policy-stats">
        <article><span>Desired allow</span><strong>{{ count($report['desired_allow']) }}</strong></article>
        <article><span>Desired block</span><strong>{{ count($report['desired_block']) }}</strong></article>
        <article><span>Pending review</span><strong>{{ count($report['pending']) }}</strong></article>
        <article><span>Policy conflicts</span><strong>{{ count($report['conflicts']) }}</strong></article>
    </div>

    <section class="panel policy-section">
        <div class="panel-heading"><div><h2>Current UniFi lists</h2><p>Paste the current allow and block lists copied from UniFi. Escaped dots such as <code>www\.example.com</code> are normalised automatically.</p></div>@if($report['compared_at'])<small>Last compared {{ \Carbon\Carbon::parse($report['compared_at'])->diffForHumans() }}</small>@endif</div>
        <form method="post" action="{{ route('admin.policy.compare') }}">@csrf
            <div class="fields two policy-editors">
                <label>Current UniFi allow list<textarea name="current_allowlist" rows="12" placeholder="allowed.example.org">{{ implode("\n", $report['current_allow']) }}</textarea></label>
                <label>Current UniFi block list<textarea name="current_blocklist" rows="12" placeholder="blocked.example.org">{{ implode("\n", $report['current_block']) }}</textarea></label>
            </div>
            <div class="form-actions"><button class="button primary">Save snapshot and compare</button></div>
        </form>
    </section>

    <section class="panel policy-section">
        <div class="panel-heading"><div><h2>Daily change set</h2><p>These lists compare the current UniFi snapshot with today’s desired state. “Review removal” is advisory so unmanaged entries are never removed automatically.</p></div></div>
        <div class="change-grid">
            @foreach([
                ['Add to allow list', 'allow_add', 'positive'],
                ['Review removal from allow list', 'allow_review_remove', 'neutral'],
                ['Add to block list', 'block_add', 'negative'],
                ['Remove from block list', 'block_remove', 'positive'],
            ] as [$label, $key, $tone])
                <article class="change-card {{ $tone }}"><div><h3>{{ $label }}</h3><span>{{ count($report[$key]) }}</span></div><textarea rows="8" readonly>{{ implode("\n", $report[$key]) }}</textarea></article>
            @endforeach
        </div>
    </section>

    <section class="panel policy-section">
        <div class="panel-heading"><div><h2>Copy/paste-ready desired state</h2><p>Approved, currently active requests are merged into the master allow list. Temporary approvals disappear after their end time.</p></div></div>
        <div class="fields two policy-editors">
            <label>Desired allow list<textarea rows="14" readonly>{{ implode("\n", $report['desired_allow']) }}</textarea></label>
            <label>Desired block list<textarea rows="14" readonly>{{ implode("\n", $report['desired_block']) }}</textarea></label>
        </div>
        @if($report['conflicts'])<div class="alert error"><strong>Allow/block conflicts</strong><p>Approved access wins for this daily output. Review: {{ implode(', ', $report['conflicts']) }}</p></div>@endif
        @if($report['pending'])<div class="notice"><strong>Pending requests are not yet included:</strong> {{ implode(', ', $report['pending']) }}</div>@endif
    </section>

    <section class="panel policy-section">
        <div class="panel-heading"><div><h2>Managed master policy</h2><p>Use this for permanent school-wide allow and block entries. Approved requests are layered on top without modifying this source list.</p></div></div>
        <form method="post" action="{{ route('admin.policy.master') }}">@csrf
            <div class="fields two policy-editors">
                <label>Master allow list<textarea name="master_allowlist" rows="14">{{ implode("\n", $report['master_allow']) }}</textarea></label>
                <label>Master block list<textarea name="master_blocklist" rows="14">{{ implode("\n", $report['master_block']) }}</textarea></label>
            </div>
            <div class="form-actions"><button class="button primary">Save master policy</button></div>
        </form>
    </section>
</x-layouts.app>
