<x-layouts.app title="{{ $guide['title'] }}">
    @php($opens = $websiteRequest->accessFrom())
    <div class="ready-page">
        <section class="panel ready-card">
            <p class="eyebrow">{{ $websiteRequest->reference }}</p>
            <h1>{{ $guide['title'] }}</h1>
            <p class="ready-status">
                @if($opens && $opens->isFuture())
                    Approved. Access to <strong>{{ implode(', ', $websiteRequest->approved_domains ?: $websiteRequest->domains) }}</strong> opens {{ $opens->local()->format('l j M \a\t g:i a') }}.
                @elseif($websiteRequest->status === \App\Enums\RequestStatus::Active)
                    Approved and applied: <strong>{{ implode(', ', $websiteRequest->approved_domains ?: $websiteRequest->domains) }}</strong>{{ $websiteRequest->expires_at ? ' until '.$websiteRequest->expires_at->local()->format('j M Y g:i a') : '' }}.
                @else
                    Approved: <strong>{{ implode(', ', $websiteRequest->approved_domains ?: $websiteRequest->domains) }}</strong>. It is being applied to the network now; this usually takes a minute or two.
                @endif
            </p>
            <p>{{ $guide['intro'] }}</p>
            <ol class="ready-steps">@foreach($steps as $step)<li>{{ $step }}</li>@endforeach</ol>
            <div class="form-actions">
                <a class="button secondary" href="{{ route('requests.show', $websiteRequest) }}">View the request</a>
                <a class="button secondary" href="{{ route('checks.create', ['websites' => implode("\n", $websiteRequest->domains)]) }}">Check it from the school network</a>
                <a class="button primary" href="https://{{ $websiteRequest->domains[0] }}" target="_blank" rel="noopener">Open {{ $websiteRequest->domains[0] }}</a>
            </div>
        </section>
    </div>
</x-layouts.app>
