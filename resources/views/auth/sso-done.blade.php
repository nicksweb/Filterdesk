<x-layouts.app title="Signed in">
    <div class="auth-wrap"><section class="auth-card" data-sso-done data-after="{{ $after }}"><p class="eyebrow">Single sign-on</p><h1>You're signed in</h1>
        <p>You can close this window and carry on where you were. <a href="{{ $after }}">Or open Filterdesk here.</a></p>
    </section></div>
</x-layouts.app>
