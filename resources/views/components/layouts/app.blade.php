<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'IT Requests' }} · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}?v={{ config('version.app') }}">
    <link rel="stylesheet" href="{{ asset('ui.css') }}?v={{ config('version.app') }}">
    <script src="{{ asset('app.js') }}?v={{ config('version.app') }}" defer></script>
    {{ $head ?? '' }}
</head>
<body>
    <header class="topbar">
        <a class="brand" href="{{ route('dashboard') }}"><span class="brand-mark">IT</span><span>{{ config('app.name') }}</span></a>
        @auth
            <nav aria-label="Primary navigation">
                <a href="{{ route('dashboard') }}" @class(['active' => request()->routeIs('dashboard')])>Overview</a>
                <a href="{{ route('checks.create') }}" @class(['active' => request()->routeIs('checks.*')])>Check a website</a>
                <a href="{{ route('requests.index') }}" @class(['active' => request()->routeIs('requests.*')])>Requests</a>
                @if(auth()->user()->isIt())
                    <a href="{{ route('admin.policy.index') }}" @class(['active' => request()->routeIs('admin.policy.*')])>Policy lists</a>
                @endif
                @if(auth()->user()->isAdmin())
                    <a href="{{ route('admin.users.index') }}" @class(['active' => request()->routeIs('admin.users.*')])>Users</a>
                    <a href="{{ route('admin.settings.show') }}" @class(['active' => request()->routeIs('admin.settings.*', 'admin.audit', 'admin.diagnostics')])>Settings</a>
                @endif
            </nav>
            <div class="account">
                <a class="account-link" href="{{ route('profile.edit') }}"><span><strong>{{ auth()->user()->name }}</strong><small>{{ ucfirst(auth()->user()->role) }}</small></span></a>
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
        @if(auth()->check() && auth()->user()->isAdmin() && request()->routeIs('admin.settings.*', 'admin.audit', 'admin.diagnostics'))
            <nav class="subnav" aria-label="Administration">
                <a href="{{ route('admin.settings.show') }}" @class(['active' => request()->routeIs('admin.settings.*')])>Settings</a>
                <a href="{{ route('admin.audit') }}" @class(['active' => request()->routeIs('admin.audit')])>Audit log</a>
                <a href="{{ route('admin.diagnostics') }}" @class(['active' => request()->routeIs('admin.diagnostics')])>Diagnostics</a>
            </nav>
        @endif
        @if(auth()->check() && auth()->user()->isIt() && request()->routeIs('admin.policy.*'))
            <nav class="subnav" aria-label="Web filtering">
                <a href="{{ route('admin.policy.index') }}" @class(['active' => request()->routeIs('admin.policy.index', 'admin.policy.lists.*', 'admin.policy.policies.*')])>Policies &amp; lists</a>
                <a href="{{ route('admin.policy.networks.index') }}" @class(['active' => request()->routeIs('admin.policy.networks.*')])>Networks &amp; filters</a>
                <a href="{{ route('admin.policy.scan.show') }}" @class(['active' => request()->routeIs('admin.policy.scan.*')])>Domain scanner</a>
            </nav>
        @endif
        {{ $slot }}
        <footer class="app-footer">IT Requests {{ config('version.app') }}</footer>
    </main>
</body>
</html>
