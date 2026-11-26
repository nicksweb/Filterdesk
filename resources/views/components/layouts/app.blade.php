<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.product_name', 'Filterdesk') }} · {{ config('app.name') }}</title>
    <link rel="icon" href="{{ route('brand.icon', ['v' => config('app.icon_version')]) }}">
    <link rel="apple-touch-icon" href="{{ route('brand.icon', ['v' => config('app.icon_version')]) }}">
    <link rel="stylesheet" href="{{ asset('app.css') }}?v={{ config('version.app') }}">
    <link rel="stylesheet" href="{{ asset('ui.css') }}?v={{ config('version.app') }}">
    <script src="{{ asset('app.js') }}?v={{ config('version.app') }}" defer></script>
    {{ $head ?? '' }}
</head>
<body>
    <header class="topbar">
        <a class="brand" href="{{ route('dashboard') }}">@if(filled(app(\App\Services\SettingsRepository::class)->get('branding.icon_data')))<img class="brand-icon" src="{{ route('brand.icon', ['v' => config('app.icon_version')]) }}" alt="">@else<span class="brand-mark">{{ config('app.brand_mark', 'IT') }}</span>@endif<span>{{ config('app.name') }}</span></a>
        @auth
            <nav aria-label="Primary navigation">
                @php($navUser = auth()->user())
                <a href="{{ route('dashboard') }}" @class(['active' => request()->routeIs('dashboard')])>Home</a>
                @if($navUser->isIt())
                    @php($inboxCount = \App\Models\WebsiteRequest::query()->whereIn('status', \App\Enums\RequestStatus::open())->count())
                    @php($changesCount = \App\Models\PolicyEntry::query()->pending()->count() + \App\Models\WebsiteRequest::query()->where('status', \App\Enums\RequestStatus::AwaitingSecondApproval)->count())
                    @php($reviewCount = \App\Models\PolicyEntry::query()->reviewable()->whereNull('pending_action')->where('review_at', '<=', now())->count())
                    <a href="{{ route('requests.index') }}" @class(['active' => request()->routeIs('requests.*', 'reviews.*')])>Inbox @if($inboxCount + $reviewCount)<span class="nav-count" aria-label="{{ $inboxCount }} open requests, {{ $reviewCount }} reviews due">{{ $inboxCount + $reviewCount }}</span>@endif</a>
                    <a href="{{ route('checks.create') }}" @class(['active' => request()->routeIs('checks.*')])>Check a website</a>
                    <a href="{{ route('admin.policy.index') }}" @class(['active' => request()->routeIs('admin.policy.*') && ! request()->routeIs('admin.policy.changes.*', 'admin.policy.versions.*', 'admin.policy.blocklists.*')])>Policies</a>
                    <a href="{{ route('admin.policy.changes.index') }}" @class(['active' => request()->routeIs('admin.policy.changes.*', 'admin.policy.versions.*')])>Changes @if($changesCount)<span class="nav-count" aria-label="{{ $changesCount }} waiting for a second officer">{{ $changesCount }}</span>@endif</a>
                    <a href="{{ route('admin.insights') }}" @class(['active' => request()->routeIs('admin.insights*')])>Insights</a>
                    <a href="{{ route('admin.health') }}" @class(['active' => request()->routeIs('admin.health', 'admin.diagnostics')])>Health</a>
                    @if($navUser->isAdmin())
                        <a href="{{ route('admin.policy.blocklists.index') }}" @class(['active' => request()->routeIs('admin.policy.blocklists.*')])>Protection</a>
                        <a href="{{ route('admin.settings.show') }}" @class(['active' => request()->routeIs('admin.settings.*', 'admin.audit', 'admin.users.*')])>Admin</a>
                    @endif
                @else
                    <a href="{{ route('checks.create') }}" @class(['active' => request()->routeIs('checks.*')])>Check a website</a>
                    <a href="{{ route('requests.index') }}" @class(['active' => request()->routeIs('requests.*')])>My requests</a>
                    <a href="{{ route('why') }}" @class(['active' => request()->routeIs('why')])>Why is this blocked?</a>
                    <a href="{{ route('help') }}" @class(['active' => request()->routeIs('help')])>Help</a>
                @endif
            </nav>
            <div class="account">
                <a class="account-link" href="{{ route('profile.edit') }}"><span><strong>{{ auth()->user()->name }}</strong><small>{{ ['teacher' => 'Staff', 'it' => 'IT', 'admin' => 'Administrator', 'student' => 'Student'][auth()->user()->role] ?? ucfirst(auth()->user()->role) }}</small></span></a>
                <form method="post" action="{{ route('logout') }}">@csrf<button class="link-button">Sign out</button></form>
            </div>
        @endauth
    </header>

    @if(session()->has('impersonator_id') && auth()->check())
        <div class="impersonation-bar" role="status">
            <span>Viewing as <strong>{{ auth()->user()->name }}</strong> · {{ \App\Http\Controllers\Admin\ImpersonationController::PREVIEW_ROLES[auth()->user()->role] ?? ucfirst(auth()->user()->role) }} view. Actions you take are real and recorded against this account.</span>
            <form method="post" action="{{ route('impersonation.stop') }}">@csrf<button class="button small">Return to my account</button></form>
        </div>
    @endif

    <main class="shell">
        @if(session('success'))<div class="alert success" role="status">{{ session('success') }}</div>@endif
        @if(session('error'))<div class="alert error" role="alert">{{ session('error') }}</div>@endif
        @if($errors->any())
            <div class="alert error" role="alert"><strong>Please check the form.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        @if(auth()->check() && auth()->user()->isAdmin() && request()->routeIs('admin.settings.*', 'admin.audit', 'admin.users.*'))
            <nav class="subnav" aria-label="Administration">
                <a href="{{ route('admin.settings.show') }}" @class(['active' => request()->routeIs('admin.settings.*')])>Settings</a>
                <a href="{{ route('admin.users.index') }}" @class(['active' => request()->routeIs('admin.users.*')])>Users</a>
                <a href="{{ route('admin.audit') }}" @class(['active' => request()->routeIs('admin.audit')])>Audit log</a>
            </nav>
        @endif
        @if(auth()->check() && auth()->user()->isIt() && request()->routeIs('requests.index', 'reviews.*'))
            <nav class="subnav" aria-label="Inbox">
                <a href="{{ route('requests.index') }}" @class(['active' => request()->routeIs('requests.index')])>Requests @if($inboxCount ?? 0)<span class="nav-count">{{ $inboxCount }}</span>@endif</a>
                <a href="{{ route('reviews.index') }}" @class(['active' => request()->routeIs('reviews.*')])>Reviews @if($reviewCount ?? 0)<span class="nav-count">{{ $reviewCount }}</span>@endif</a>
            </nav>
        @endif
        @if(auth()->check() && auth()->user()->isIt() && request()->routeIs('admin.policy.*') && ! request()->routeIs('admin.policy.changes.*', 'admin.policy.versions.*', 'admin.policy.blocklists.*'))
            <nav class="subnav" aria-label="Policies">
                <a href="{{ route('admin.policy.index') }}" @class(['active' => request()->routeIs('admin.policy.index', 'admin.policy.lists.*', 'admin.policy.policies.*')])>Policies &amp; lists</a>
                <a href="{{ route('admin.policy.map') }}" @class(['active' => request()->routeIs('admin.policy.map')])>Filtering map</a>
                <a href="{{ route('admin.policy.networks.index') }}" @class(['active' => request()->routeIs('admin.policy.networks.*')])>Networks &amp; filters</a>
                <a href="{{ route('admin.policy.explorer') }}" @class(['active' => request()->routeIs('admin.policy.explorer')])>Access explorer</a>
                @foreach(\App\Models\NetworkType::query()->ordered()->get() as $navType)<a href="{{ route('admin.policy.types.show', $navType) }}" @class(['active' => request()->routeIs('admin.policy.types.show') && request()->route('type')?->is($navType)])>{{ $navType->name }}</a>@endforeach
                <a href="{{ route('admin.policy.scan.show') }}" @class(['active' => request()->routeIs('admin.policy.scan.*')])>Domain scanner</a>
                @if(auth()->user()->isAdmin())<a href="{{ route('admin.policy.firewall-allow.index') }}" @class(['active' => request()->routeIs('admin.policy.firewall-allow.*')])>Firewall allow lists</a>@endif
                @if(auth()->user()->isAdmin())<a href="{{ route('admin.policy.setup.show') }}" @class(['active' => request()->routeIs('admin.policy.setup.*')])>Guided setup</a>@endif
            </nav>
        @endif
        {{ $slot }}
        <footer class="app-footer">{{ config('app.product_name', 'Filterdesk') }} {{ config('version.app') }}@if(app(\App\Services\SettingsRepository::class)->get('branding.project_link', true)) · <a href="{{ config('app.project_url') }}" target="_blank" rel="noopener">Built with Filterdesk, open source on GitHub</a>@endif</footer>
    </main>
</body>
</html>
