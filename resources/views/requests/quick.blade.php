<x-layouts.app title="{{ $decision === 'approve' ? 'Approve' : 'Decline' }} {{ $websiteRequest->reference }}">
    <div class="auth-wrap"><div class="auth-card">
        <p class="eyebrow">{{ $websiteRequest->reference }}</p>
        <h1>{{ $decision === 'approve' ? 'Approve' : 'Decline' }}?</h1>
        <p><strong>{{ implode(', ', $websiteRequest->domains) }}</strong> for {{ $websiteRequest->access_scope?->label() }}, requested by {{ $websiteRequest->requester->name }}.</p>
        <p class="prose">{{ $websiteRequest->use_case ?: 'No reason given.' }}</p>
        @if(! $websiteRequest->status->isOpen())
            <div class="alert">This request is already {{ strtolower($websiteRequest->status->label()) }}.</div>
        @else
            <form method="post" action="{{ route('it.requests.action', $websiteRequest) }}">@csrf
                <input type="hidden" name="action" value="{{ $decision }}">
                @if($decision === 'reject')<label>Reason for the requester<textarea name="message" rows="3" required></textarea></label>@endif
                <div class="form-actions"><a class="button secondary" href="{{ route('requests.show', $websiteRequest) }}">Open the full request</a><button class="button primary">{{ $decision === 'approve' ? 'Approve as requested' : 'Decline' }}</button></div>
            </form>
            @if($decision === 'approve')<p class="hint">Approves the requested hostnames and networks. Site-wide or sensitive requests still need a second IT officer.</p>@endif
        @endif
    </div></div>
</x-layouts.app>
