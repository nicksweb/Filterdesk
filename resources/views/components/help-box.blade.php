{{-- "Need help?": the school helpdesk and the IT team's support article (Settings → Help and support), and the How to page. --}}
@php($support = app(\App\Services\Support::class)->all())
<aside class="panel help-box">
    <h2>Need help?</h2>
    <p><a href="{{ route('help') }}">How to check a website and ask for access</a>@if($support['article_url'] !== '') · <a href="{{ $support['article_url'] }}" target="_blank" rel="noopener">{{ $support['article_label'] ?: 'Support article' }}</a>@endif</p>
    @if(app(\App\Services\Support::class)->hasContact())
        <p><strong>{{ $support['name'] ?: 'IT helpdesk' }}</strong>
            @if($support['email'] !== '')<br><a href="mailto:{{ $support['email'] }}">{{ $support['email'] }}</a>@endif
            @if($support['phone'] !== '')<br><a href="tel:{{ preg_replace('/[^0-9+]/', '', $support['phone']) }}">{{ $support['phone'] }}</a>@endif
            @if($support['hours'] !== '')<br><small>{{ $support['hours'] }}</small>@endif
            @if($support['location'] !== '')<br><small>{{ $support['location'] }}</small>@endif
        </p>
        @if($support['note'] !== '')<p class="hint">{{ $support['note'] }}</p>@endif
    @endif
</aside>
