<x-layouts.app title="{{ $type->name }} policy">
    <x-slot:head><link rel="stylesheet" href="{{ asset('admin.css') }}"></x-slot:head>
    <div class="page-heading">
        <div>
            <p class="eyebrow">Policies · network type</p>
            <h1>{{ $type->name }}</h1>
            <p>{{ $type->description ?: 'What networks of this type may reach, and why.' }}</p>
        </div>
        <div class="button-row"><a class="button secondary" href="{{ route('admin.policy.explorer', ['type' => $type->key]) }}">Explore access</a></div>
    </div>

    <div class="stat-grid three">
        <article><span>Allow entries</span><strong>{{ number_format($summary['allow']->sum()) }}</strong><small>@foreach($summary['allow'] as $source => $count){{ \App\Enums\EntrySource::from($source)->label() }} {{ number_format($count) }}@if(!$loop->last) · @endif @endforeach</small></article>
        <article><span>Block entries</span><strong>{{ number_format($summary['block']->sum()) }}</strong><small>@foreach($summary['block'] as $source => $count){{ \App\Enums\EntrySource::from($source)->label() }} {{ number_format($count) }}@if(!$loop->last) · @endif @endforeach</small></article>
        <article><span>Timed and recurring</span><strong>{{ number_format($timed + $scheduled) }}</strong><small>{{ $timed }} with an end date · {{ $scheduled }} on a schedule</small></article>
    </div>

    <section class="panel policy-section">
        <div class="panel-heading"><div><h2>Content filters</h2><p>Each policy of this type, the controller filter it writes to, the categories that filter blocks and whether it is in sync.</p></div></div>
        @forelse($policies as $item)
            <article class="probe-plan">
                <h3><a href="{{ route('admin.policy.policies.show', $item['policy']) }}">{{ $item['policy']->name }}</a> @if($item['policy']->isLayer())<small>layer</small>@endif <x-sync-status :policy="$item['policy']" /></h3>
                @if($item['filter'])
                    <p class="hint">Controller filter <strong>{{ $item['filter']['name'] }}</strong> · {{ number_format(count($item['filter']['allow'] ?? [])) }} allowed · {{ number_format(count($item['filter']['block'] ?? [])) }} blocked</p>
                    <p>Blocked categories: @forelse((array) ($item['filter']['categories'] ?? []) as $category)<span class="chip">{{ ucfirst(strtolower(str_replace('_', ' ', $category))) }}</span>@empty <span class="hint">none</span>@endforelse</p>
                @else
                    <p class="network-status warn">Not linked to a controller filter, so nothing is enforced for this policy yet.</p>
                @endif
            </article>
        @empty
            <p class="empty">No policy uses this type yet. Guided setup creates one per controller filter.</p>
        @endforelse
    </section>

    <section class="panel policy-section">
        <div class="panel-heading"><div><h2>Exam mode</h2><p>A block list that only applies during exams.</p></div></div>
        <p>Exam mode is <strong>{{ \App\Models\NetworkType::EXAM_MODES[$type->exam_mode ?? 'off'] }}</strong>@if($type->examActive()) and <strong>active now</strong>@endif.
            @if($examList)<a href="{{ route('admin.policy.lists.show', $examList) }}">{{ $examList->entries()->count() }} site(s) blocked during exams</a>.@endif
            @if(auth()->user()->isAdmin())<a href="{{ route('admin.settings.show', 'types') }}">Change it</a> · <a href="{{ route('admin.settings.show', 'calendar') }}">exam periods</a>@endif</p>
    </section>

    <section class="panel policy-section">
        <div class="panel-heading"><div><h2>Lists that apply</h2><p>Site-wide lists, this type's own lists and each policy's lists. The most specific list wins, then the newest entry; an entry added on purpose beats a feed or import.</p></div></div>
        <div class="table-wrap"><table>
            <thead><tr><th>List</th><th>Kind</th><th>Reaches</th><th>Entries</th></tr></thead>
            <tbody>
                @foreach($lists as $list)
                    <tr><td><a href="{{ route('admin.policy.lists.show', $list) }}">{{ $list->name }}</a>@if($list->purpose === 'exam')<small>exam mode only</small>@endif</td><td>{{ ucfirst($list->kind->value) }}</td><td>{{ $list->scope->value === 'site' ? 'Every network' : ($list->scope->value === 'audience' ? 'Every '.$type->name.' policy' : ($list->filterPolicy?->name ?? 'One policy')) }}</td><td>{{ number_format($list->entries()->count()) }}</td></tr>
                @endforeach
            </tbody>
        </table></div>
    </section>
</x-layouts.app>
