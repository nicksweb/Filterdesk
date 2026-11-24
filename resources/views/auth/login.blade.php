<x-layouts.app title="Sign in">
    <div class="auth-wrap"><section class="auth-card"><p class="eyebrow">Secure school access</p><h1>Welcome back</h1><p>Sign in to check access or manage a website request.</p>
        @if(session('success'))<div class="alert success">{{ session('success') }}</div>@endif
        @if($oidcEnabled)
            {{-- Inside a portal iframe, app.js opens this in a popup: identity providers can't sign in inside a frame on another site. --}}
            <a class="button primary full" href="{{ route('auth.oidc') }}" data-sso-link data-popup-href="{{ route('auth.oidc', ['popup' => 1]) }}" data-after="{{ route('dashboard') }}">Continue with single sign-on</a>
            <div class="notice sso-embedded" data-sso-waiting hidden>Finish signing in in the window that opened. If no window opened, your browser blocked it: <a href="{{ route('login') }}" target="_blank" rel="noopener">open Filterdesk in a new tab</a>.</div>
            @if(request('sso') === 'done')
                <div class="notice sso-embedded" data-sso-storage>You're signed in, but your browser keeps this embedded page separate from the window you signed in with.
                    <button class="button primary small" type="button" data-storage-access data-after="{{ route('dashboard') }}">Continue here</button>
                    <small data-storage-denied hidden>Your browser didn't allow it. <a href="{{ route('dashboard') }}" target="_blank" rel="noopener">Open Filterdesk in a new tab</a> instead.</small></div>
            @endif
        @endif
        @if($oidcEnabled && $localEnabled)<div class="divider"><span>Emergency access</span></div>@endif
        @if($localEnabled)<form method="post" action="{{ route('auth.local') }}">@csrf<label>Email address<input name="email" type="email" autocomplete="username" required value="{{ old('email') }}"></label><label>Password<input name="password" type="password" autocomplete="current-password" required></label><button class="button {{ $oidcEnabled ? 'secondary' : 'primary' }} full">Sign in</button></form>@endif
    </section></div>
</x-layouts.app>
