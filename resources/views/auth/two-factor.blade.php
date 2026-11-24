<x-layouts.app title="Two-factor sign-in">
    <div class="page-heading"><div><p class="eyebrow">Your account</p><h1>Two-factor sign-in</h1>
        <p>A 6-digit code from an app on your phone, as well as your password, so a stolen password alone can't be used to sign in{{ $user->isIt() ? ' or change filtering' : '' }}.</p></div>
        <a class="button secondary" href="{{ route('profile.edit') }}">Profile</a></div>

    @if(session('recovery_codes'))
        <section class="panel form-panel recovery-codes">
            <h2>Your recovery codes</h2>
            <p>Each code works once, if you lose your phone. Keep them somewhere safe, such as a password manager. They won't be shown again.</p>
            <ul class="mono">@foreach(session('recovery_codes') as $code)<li>{{ $code }}</li>@endforeach</ul>
        </section>
    @endif

    <section class="panel form-panel">
        @if($user->hasTwoFactor())
            <p><span class="status status-ok">On</span> since {{ $user->two_factor_confirmed_at->local()->format('j M Y') }}. {{ $remaining }} recovery code(s) left.</p>
            <form method="post" action="{{ route('profile.two-factor.recovery-codes') }}">@csrf
                <h3>New recovery codes</h3>
                <div class="fields two"><label>Code from your app<input name="code" inputmode="numeric" autocomplete="one-time-code" required maxlength="20"></label></div>
                @error('code')<p class="field-error">{{ $message }}</p>@enderror
                <div class="form-actions"><button class="button secondary">Make new recovery codes</button></div>
            </form>
            @unless($required)
                <form method="post" action="{{ route('profile.two-factor.disable') }}">@csrf @method('DELETE')
                    <h3>Turn it off</h3>
                    <div class="fields two"><label>Code from your app<input name="code" inputmode="numeric" autocomplete="one-time-code" required maxlength="20"></label></div>
                    <div class="form-actions"><button class="button secondary" data-confirm="Turn off two-factor sign-in?">Turn off</button></div>
                </form>
            @else
                <p class="hint">Your account needs two-factor sign-in, so it can't be turned off. If you change phones, an admin can reset it (Users), and you set it up again.</p>
            @endunless
        @elseif($secret)
            <h2>Add Filterdesk to your authenticator app</h2>
            <ol class="setup-steps">
                <li>Open an authenticator app on your phone: Microsoft Authenticator, Google Authenticator, 1Password, Bitwarden or any other that does 6-digit codes.</li>
                <li>Add an account. On the phone you're reading this on, <a href="{{ $uri }}">tap here to add it</a>. Otherwise choose <em>Enter a setup key</em> and type:
                    <p class="setup-key mono">{{ trim(chunk_split($secret, 4, ' ')) }}</p>
                    <small class="hint">Account: {{ $user->email }} · Type: time-based</small></li>
                <li>Enter the 6-digit code the app shows:</li>
            </ol>
            <form method="post" action="{{ route('profile.two-factor.confirm') }}">@csrf
                <div class="fields two"><label>Code<input name="code" inputmode="numeric" autocomplete="one-time-code" required maxlength="20" autofocus></label></div>
                @error('code')<p class="field-error">{{ $message }}</p>@enderror
                <div class="form-actions"><button class="button primary">Turn on two-factor sign-in</button></div>
            </form>
        @else
            <p>{{ $required ? 'Your account needs two-factor sign-in.' : 'Two-factor sign-in is off.' }} Setting it up takes a minute and needs an authenticator app on your phone.</p>
            <form method="post" action="{{ route('profile.two-factor.start') }}">@csrf<div class="form-actions"><button class="button primary">Set up two-factor sign-in</button></div></form>
        @endif
    </section>
</x-layouts.app>
