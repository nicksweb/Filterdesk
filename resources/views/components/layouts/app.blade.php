<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'IT Requests' }} · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}">
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
                @if(auth()->user()->isAdmin())
                    <a href="{{ route('admin.users.index') }}" @class(['active' => request()->routeIs('admin.users.*')])>Users</a>
                    <a href="{{ route('admin.integrations.index') }}" @class(['active' => request()->routeIs('admin.integrations.*')])>Integrations</a>
                @endif
            </nav>
            <div class="account">
                <span><strong>{{ auth()->user()->name }}</strong><small>{{ ucfirst(auth()->user()->role) }}</small></span>
                <form method="post" action="{{ route('logout') }}">@csrf<button class="link-button">Sign out</button></form>
            </div>
        @endauth
    </header>

    <main class="shell">
        @if(session('success'))<div class="alert success" role="status">{{ session('success') }}</div>@endif
        @if(session('error'))<div class="alert error" role="alert">{{ session('error') }}</div>@endif
        @if($errors->any())
            <div class="alert error" role="alert"><strong>Please check the form.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        {{ $slot }}
    </main>
</body>
</html>
