<x-layouts.app title="Sign in">
    <div class="auth-wrap"><section class="auth-card"><p class="eyebrow">Secure school access</p><h1>Welcome back</h1><p>Sign in to check access or manage a website request.</p>
        @if(session('success'))<div class="alert success">{{ session('success') }}</div>@endif
        @if($oidcEnabled)<a class="button primary full" href="{{ route('auth.oidc') }}">Continue with single sign-on</a>@endif
        @if($oidcEnabled && $localEnabled)<div class="divider"><span>Emergency access</span></div>@endif
        @if($localEnabled)<form method="post" action="{{ route('auth.local') }}">@csrf<label>Email address<input name="email" type="email" autocomplete="username" required value="{{ old('email') }}"></label><label>Password<input name="password" type="password" autocomplete="current-password" required></label><button class="button {{ $oidcEnabled ? 'secondary' : 'primary' }} full">Sign in</button></form>@endif
    </section></div>
</x-layouts.app>
