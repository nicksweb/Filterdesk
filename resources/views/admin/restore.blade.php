<x-layouts.app title="Restore to a point in time">
    <x-slot:head><link rel="stylesheet" href="{{ asset('admin.css') }}"></x-slot:head>
    <div class="page-heading">
        <div><p class="eyebrow">Changes</p><h1>Restore to a point in time</h1>
            <p>Put content filters (CyberSecure DNS allow and block lists, on every network that uses them) and firewall allow lists back to how they were at a date and time. Imported IP block lists aren't included: they're rebuilt from their feeds.</p></div>
    </div>

    @if($status)
        <section @class(['panel', 'policy-section'])>
            <h2>{{ $status['state'] === 'running' ? 'Restoring to '.$status['when'].'…' : 'Last restore: '.$status['when'] }}</h2>
            @if($status['state'] === 'running')<p class="hint">Started {{ \Illuminate\Support\Carbon::parse($status['since'])->diffForHumans() }}. Refresh to see the results.</p>@endif
            @if($status['results'])<ul class="rule-notes">@foreach($status['results'] as $result)<li><span class="status status-{{ $result['ok'] ? 'ok' : 'failed' }}">{{ $result['ok'] ? 'Done' : 'Failed' }}</span> {{ $result['message'] }}</li>@endforeach</ul>@endif
        </section>
    @endif

    <form class="panel form-panel" method="get" action="{{ route('admin.restore.index') }}">
        <div class="search-line">
            <label>How things were at <span class="optional">{{ app(\App\Services\SettingsRepository::class)->timezone() }}</span><input type="datetime-local" name="at" required value="{{ $at }}" max="{{ now(app(\App\Services\SettingsRepository::class)->timezone())->format('Y-m-d\TH:i') }}"></label>
            <button class="button secondary">Show</button>
        </div>
        <p class="hint">{{ $since ? 'History starts '.$since->format('j M Y g:i a').'. A version is kept whenever a filter or list changes (read from UniFi every 15 minutes, and at every push), for '.\App\Services\Policy\StateHistory::KEEP_DAYS.' days.' : 'No history yet. Versions are recorded from now on: each filter as UniFi has it (every 15 minutes and at every push) and each allow list when it changes.' }}</p>
    </form>

    @if($when)
        <form class="panel policy-section" method="post" action="{{ route('admin.restore.store') }}">@csrf
            <input type="hidden" name="at" value="{{ $at }}">
            <div class="panel-heading"><div><h2>At {{ $when->format('l j M Y, g:i a') }}</h2><p>Tick what to put back. Filters and lists that are the same now are not ticked.</p></div></div>
            @forelse($rows as $row)
                @php($snapshot = $row['snapshot'])
                @php($difference = $row['difference'])
                <label class="choice compact">
                    <input type="checkbox" name="snapshots[]" value="{{ $snapshot->id }}" @checked($row['changes'] > 0) @disabled($row['changes'] === 0)>
                    <span><strong>{{ $snapshot->name }}</strong>
                        <small>{{ $snapshot->kind === \App\Models\ConfigSnapshot::CONTENT_FILTER ? 'Content filter' : 'Firewall allow list' }} · version from {{ $snapshot->taken_at->setTimezone(app(\App\Services\SettingsRepository::class)->timezone())->format('j M g:i a') }} ·
                            @if($row['changes'] === 0) the same now
                            @elseif($snapshot->kind === \App\Models\ConfigSnapshot::CONTENT_FILTER)
                                restoring would: allow +{{ count($difference['allow_add']) }} / −{{ count($difference['allow_remove']) }}, block +{{ count($difference['block_add']) }} / −{{ count($difference['block_remove']) }}
                            @else
                                restoring would: entries +{{ count($difference['entries_add']) }} / −{{ count($difference['entries_remove']) }}
                            @endif
                        </small>
                        @if($row['changes'] > 0)
                            <details class="hint"><summary>Show the entries</summary>
                                @foreach($difference as $kind => $values)
                                    @if($values)<p><strong>{{ ['allow_add' => 'Allowed again', 'allow_remove' => 'No longer allowed', 'block_add' => 'Blocked again', 'block_remove' => 'No longer blocked', 'entries_add' => 'Let through again', 'entries_remove' => 'No longer let through'][$kind] }}</strong> ({{ count($values) }}): {{ implode(', ', array_slice($values, 0, 50)) }}{{ count($values) > 50 ? ' …and '.(count($values) - 50).' more' : '' }}</p>@endif
                                @endforeach
                            </details>
                        @endif
                    </span>
                </label>
            @empty
                <p class="empty">Nothing was recorded at or before that time.</p>
            @endforelse
            @if($rows->where('changes', '>', 0)->isNotEmpty())
                @unless($canWrite)<div class="alert error">Pushing is off (Settings → Network provider → Mode), so content filters can't be written. Firewall allow lists are still restored here and pushed when pushing is on.</div>@endunless
                <label class="choice"><input type="checkbox" name="understood" value="1" required><span><strong>Put the ticked filters and lists back as they were</strong><small>Content filters are written to UniFi straight away (protected sites are still never blocked) and their policies follow; a policy whose own lists still differ is put on hold until someone checks and pushes it. Everything is in the push history and the audit log, so a restore can be undone the same way.</small></span></label>
                <div class="form-actions"><button class="button primary" data-confirm="Restore the ticked filters and lists to {{ $when->format('j M Y g:i a') }}?">Restore</button></div>
            @endif
        </form>
    @endif
</x-layouts.app>
