<x-layouts.app title="Profile">
    <div class="page-heading"><div><p class="eyebrow">Your account</p><h1>{{ $user->name }}</h1><p>{{ $user->email }} · {{ ucfirst($user->role) }} · {{ $user->auth_source === 'oidc' ? 'Single sign-on' : 'Local account' }}</p></div></div>
    @if($user->isIt() || $user->hasTwoFactor() || $user->auth_source !== 'oidc')
        <section class="panel form-panel">
            <h2>Two-factor sign-in</h2>
            <p>{{ $user->hasTwoFactor() ? 'On. A code from your authenticator app is asked for when you sign in.' : 'Off. Add a code from an app on your phone to your sign-in.' }} <a href="{{ route('profile.two-factor') }}">{{ $user->hasTwoFactor() ? 'Manage' : 'Set it up' }}</a></p>
        </section>
    @endif
    <section class="panel form-panel">
        <h2>Notifications</h2>
        <form method="post" action="{{ route('profile.update') }}">@csrf @method('PUT')
            @if($user->isIt())
                <label class="choice"><input type="hidden" name="notify_new_requests" value="0"><input type="checkbox" name="notify_new_requests" value="1" @checked($user->notify_new_requests)><span><strong>Email me about new requests and replies</strong><small>In addition to the shared IT notification address.</small></span></label>
            @else
                <p class="section-copy">You are emailed when IT decides on your requests or asks a question.</p>
            @endif
            @if($user->isIt())<div class="form-actions"><button class="button primary">Save</button></div>@endif
        </form>
    </section>
</x-layouts.app>
