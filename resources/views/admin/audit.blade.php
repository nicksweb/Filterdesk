<x-layouts.app title="Audit log">
    <x-slot:head><link rel="stylesheet" href="{{ asset('admin.css') }}"></x-slot:head>
    <div class="page-heading"><div><p class="eyebrow">Administration</p><h1>Audit log</h1><p>Privileged actions, approvals, policy and settings changes.</p></div></div>
    <form method="get" class="filter-bar">
        <label class="grow">Action<select name="action"><option value="">All actions</option>@foreach($actions as $action)<option @selected(($filters['action'] ?? '') === $action)>{{ $action }}</option>@endforeach</select></label>
        <button class="button secondary">Filter</button>
    </form>
    <section class="panel table-wrap">
        <table>
            <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Subject</th><th>Details</th></tr></thead>
            <tbody>
                @forelse($events as $event)
                    <tr>
                        <td>{{ $event->created_at->local()->format('j M Y H:i') }}<small>{{ $event->ip_address }}</small></td>
                        <td>{{ $event->actor?->name ?? 'System' }}</td>
                        <td><code>{{ $event->action }}</code></td>
                        <td>@if($event->subject_type){{ class_basename($event->subject_type) }} #{{ $event->subject_id }}@else — @endif
                            @if($event->subject_type === \App\Models\WebsiteRequest::class)<small><a href="{{ route('requests.show', $event->subject_id) }}">Open request</a></small>@endif</td>
                        <td class="audit-meta">@if($event->metadata)<code>{{ str(json_encode($event->metadata, JSON_UNESCAPED_SLASHES))->limit(220) }}</code>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty">No audit events.</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $events->links() }}
    </section>
</x-layouts.app>
