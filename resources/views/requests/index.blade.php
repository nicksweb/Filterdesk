<x-layouts.app title="Requests">
    @php($isIt = auth()->user()->isIt())
    <div class="page-heading">
        <div><p class="eyebrow">Access workflow</p><h1>{{ $isIt ? 'Website requests' : 'My website requests' }}</h1><p>Track decisions, assignments, and temporary access.</p></div>
        @unless(auth()->user()->isStudent() && !app(\App\Services\SettingsRepository::class)->get('approval.students_can_request', false))
            <a class="button primary" href="{{ route('requests.create') }}">New request</a>
        @endunless
    </div>

    <nav class="tabs" aria-label="Request status">
        @foreach(['open' => 'Awaiting decision', 'action_required' => 'Action required', 'granted' => 'Active', 'closed' => 'Closed', 'all' => 'All'] as $key => $label)
            <a href="{{ route('requests.index', array_filter(['status' => $key, 'assigned' => $filters['assigned'] !== 'all' ? $filters['assigned'] : null, 'q' => $filters['q'] ?: null])) }}" @class(['active' => $filters['status'] === $key])>
                {{ $label }}@isset($counts[$key])<span class="count">{{ $counts[$key] }}</span>@endisset
            </a>
        @endforeach
    </nav>

    <form method="get" class="filter-bar">
        <input type="hidden" name="status" value="{{ $filters['status'] }}">
        <label class="grow">Search<input name="q" value="{{ $filters['q'] }}" placeholder="Reference, domain{{ $isIt ? ' or requester' : '' }}"></label>
        @if($isIt)
            <label>Assigned
                <select name="assigned">
                    <option value="all" @selected($filters['assigned'] === 'all')>Anyone</option>
                    <option value="me" @selected($filters['assigned'] === 'me')>Me</option>
                    <option value="unassigned" @selected($filters['assigned'] === 'unassigned')>Unassigned</option>
                </select>
            </label>
        @endif
        <button class="button secondary">Filter</button>
    </form>

    <section class="panel table-wrap">
        <table>
            <thead><tr><th>Reference</th><th>Domains</th>@if($isIt)<th>Requester</th><th>Assigned</th>@endif<th>Scope</th><th>Status</th><th>Updated</th></tr></thead>
            <tbody>
                @forelse($requests as $item)
                    <tr>
                        <td><a href="{{ route('requests.show', $item) }}">{{ $item->reference }}</a>@if($item->isBlock())<span class="tag tag-block">Block</span>@endif @if($item->requested_until)<small>Until {{ $item->requested_until->local()->format('j M') }}</small>@endif</td>
                        <td><strong>{{ implode(', ', array_slice($item->domains, 0, 2)) }}</strong>@if(count($item->domains) > 2)<small>+{{ count($item->domains) - 2 }} more</small>@endif</td>
                        @if($isIt)<td>{{ $item->requester->name }}</td><td>{{ $item->assignedTo?->name ?? '—' }}</td>@endif
                        <td>{{ $item->effectiveScope()->label() }}</td>
                        <td><x-status :status="$item->status" /></td>
                        <td>{{ $item->updated_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="empty">No requests match these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $requests->links() }}
    </section>
</x-layouts.app>
