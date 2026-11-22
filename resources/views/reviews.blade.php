<x-layouts.app title="Reviews">
    <div class="page-heading">
        <div>
            <p class="eyebrow">Exceptions</p>
            <h1>Reviews</h1>
            <p>Website exceptions are reviewed every {{ $months }} months. Keep the ones still needed; anything not reviewed is removed {{ $grace }} days after its review date.</p>
        </div>
    </div>
    <section class="panel">
        <h2>Due for review</h2>
        @if($entries->isEmpty())
            <p class="empty">Nothing is due. </p>
        @else
            <form method="post" action="{{ route('reviews.keep') }}">@csrf
                <div class="table-wrap"><table>
                    <thead><tr><th><span class="sr-only">Select</span></th><th>Site</th><th>List</th><th>Owner</th><th>Reason</th><th>Review by</th></tr></thead>
                    <tbody>
                        @foreach($entries as $entry)
                            <tr>
                                <td><input type="checkbox" name="entries[]" value="{{ $entry->id }}" checked aria-label="Keep {{ $entry->domain }}"></td>
                                <td><code>{{ $entry->domain }}</code>@if($entry->isStale())<small>Not seen in a check or request for a year</small>@endif</td>
                                <td>@if(auth()->user()->isIt())<a href="{{ route('admin.policy.lists.show', $entry->policyList) }}">{{ $entry->policyList->name }}</a>@else{{ $entry->policyList->name }}@endif<small>{{ ucfirst($entry->policyList->kind->value) }}</small></td>
                                <td>{{ $entry->owner?->name ?? 'No owner' }}</td>
                                <td>{{ $entry->note ?: '—' }}@if($entry->websiteRequest)<small><a href="{{ route('requests.show', $entry->websiteRequest) }}">{{ $entry->websiteRequest->reference }}</a></small>@endif</td>
                                <td>{{ $entry->review_at->local()->format('j M Y') }}<small class="{{ $entry->review_at->isPast() ? 'danger-text' : '' }}">removed {{ $entry->expiresUnreviewedAt()->local()->format('j M Y') }} if not kept</small></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
                <div class="button-row"><button class="button primary">Keep selected for {{ $months }} months</button></div>
                @if(auth()->user()->isIt())<p class="hint">To remove one now, open its list and remove it there (site-wide removals need a second IT officer).</p>@endif
            </form>
            {{ $entries->links() }}
        @endif
    </section>
    @if($stale->isNotEmpty())
        <section class="panel" style="margin-top:20px">
            <h2>Possibly stale</h2>
            <p class="hint">Not seen in any website check or request for a year. Consider removing them at their next review.</p>
            <div class="chip-list">@foreach($stale as $entry)<span class="chip">{{ $entry->domain }}</span>@endforeach</div>
        </section>
    @endif
</x-layouts.app>
