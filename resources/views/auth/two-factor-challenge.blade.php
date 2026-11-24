<x-layouts.app title="Sign in">
    <div class="auth-wrap"><section class="auth-card"><p class="eyebrow">Two-factor sign-in</p><h1>Enter your code</h1>
        <p>Open your authenticator app and enter the 6-digit code for {{ config('app.name') }}. Lost your phone? Enter one of your recovery codes instead.</p>
        <form method="post" action="{{ route('two-factor.verify') }}">@csrf
            <label>Code<input name="code" inputmode="numeric" autocomplete="one-time-code" required maxlength="20" autofocus></label>
            @error('code')<p class="field-error">{{ $message }}</p>@enderror
            <button class="button primary full">Sign in</button>
        </form>
        <p class="hint"><a href="{{ route('login') }}">Start again</a> · No recovery codes? Ask an admin to reset your two-factor sign-in.</p>
    </section></div>
</x-layouts.app>
