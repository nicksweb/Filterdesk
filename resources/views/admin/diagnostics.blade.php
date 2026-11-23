<x-layouts.app title="Diagnostics">
    <x-slot:head><link rel="stylesheet" href="{{ asset('admin.css') }}"></x-slot:head>
    <div class="page-heading"><div><p class="eyebrow">Administration</p><h1>Diagnostics</h1><p>Configuration and service health. <code>/health</code> gives monitors a minimal JSON status.</p></div></div>
    <section class="panel">
        <div class="check-list">
            @foreach($checks as $check)
                <div><span class="check-icon {{ $check['ok'] ? 'ok' : (($check['warn'] ?? false) ? 'warn' : 'bad') }}">{{ $check['ok'] ? '✓' : '!' }}</span><span><strong>{{ $check['label'] }}</strong><small>{{ $check['message'] }}</small></span></div>
            @endforeach
        </div>
    </section>
    <section class="panel">
        <h2>Operations</h2>
        <ul class="rule-notes">
            <li>Upgrade: set <code>FILTERDESK_VERSION</code> in <code>.env</code>, then <code>docker compose pull && docker compose up -d</code> (see docs/UPGRADING.md). The <code>migrate</code> service runs <code>php artisan filterdesk:upgrade</code> before workers start.</li>
            <li>Emergency admin password: <code>docker compose exec app php artisan filterdesk:admin-password admin@example.edu.au --enable-local-login</code></li>
            <li>Send the policy digest now: <code>docker compose exec app php artisan policy:digest --force</code></li>
            <li>Back up the database and <code>.env</code> (APP_KEY) together. See docs/DEPLOYMENT.md.</li>
        </ul>
    </section>
</x-layouts.app>
