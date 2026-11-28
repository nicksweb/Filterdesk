<x-layouts.app title="Users">
    <x-slot:head><link rel="stylesheet" href="{{ asset('admin.css') }}"></x-slot:head>
    <div class="page-heading">
        <div><p class="eyebrow">Administration</p><h1>User management</h1><p>Create, correct and delete accounts. Single sign-on creates accounts on first sign-in; local ones are for testing or emergency access.</p></div>
    </div>

    <section class="panel preview-panel">
        <div><h2>See what others see</h2><p class="section-copy">Open the app as a built-in preview account, or use <strong>View as</strong> on any staff or student below. Use <strong>Return to my account</strong> in the banner to come back.</p></div>
        <div class="button-row">
            @foreach(\App\Http\Controllers\Admin\ImpersonationController::PREVIEW_ROLES as $role => $label)
                <form method="post" action="{{ route('admin.preview', $role) }}">@csrf<button class="button secondary">Preview as {{ strtolower($label) }}</button></form>
            @endforeach
        </div>
    </section>

    <section class="panel form-panel admin-create-panel" id="on-behalf">
        <h2>Submit a request for someone</h2>
        <p class="section-copy">For someone who rang or emailed the helpdesk. You switch to them and fill in the request form; it's recorded as theirs, with you as the sender, and they get the usual emails. Someone who hasn't signed in yet gets an account now (no password); it becomes their single sign-on account when they first sign in.</p>
        <form method="post" action="{{ route('admin.users.on-behalf') }}">@csrf
            <div class="fields three">
                <label>Email address<input type="email" name="email" required maxlength="255" value="{{ old('email') }}" autocomplete="off"></label>
                <label>Name <span class="optional">for someone new</span><input name="name" maxlength="120" value="{{ old('name') }}"></label>
                <label>They are<select name="role"><option value="teacher" @selected(old('role') === 'teacher')>Staff</option><option value="student" @selected(old('role') === 'student')>A student</option></select><small>Only used for someone new.</small></label>
            </div>
            <div class="form-actions"><button class="button primary">Switch to them and open the request form</button></div>
        </form>
    </section>

    <section class="panel form-panel admin-create-panel">
        <h2>Create user</h2>
        <p class="section-copy">Set a password for a local or dummy account. Leave it blank to create an OIDC-only placeholder.</p>
        <form method="post" action="{{ route('admin.users.store') }}">
            @csrf
            <div class="fields two">
                <label>Name<input name="name" required value="{{ old('name') }}"></label>
                <label>Email<input name="email" type="email" required value="{{ old('email') }}"></label>
            </div>
            <div class="fields three">
                <label>Role<select name="role">@foreach(['student','teacher','it','admin'] as $role)<option value="{{ $role }}" @selected(old('role', 'teacher') === $role)>{{ ucfirst($role) }}</option>@endforeach</select></label>
                <label>Password <span class="optional">optional, minimum 14 characters</span><input name="password" type="password" autocomplete="new-password"></label>
                <label>Confirm password<input name="password_confirmation" type="password" autocomplete="new-password"></label>
            </div>
            <div class="form-actions split-actions">
                <label class="choice compact"><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" checked><span><strong>Enabled</strong><small>Local sign-in also requires emergency local access to remain enabled.</small></span></label>
                <button class="button primary">Create user</button>
            </div>
        </form>
    </section>

    <form method="get" class="filter-bar">
        <label class="grow">Search<input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Name or email"></label>
        <label>Role<select name="role"><option value="">All roles</option>@foreach(['student','teacher','it','admin'] as $role)<option value="{{ $role }}" @selected(($filters['role'] ?? '') === $role)>{{ ucfirst($role) }} ({{ $counts[$role] ?? 0 }})</option>@endforeach</select></label>
        <button class="button secondary">Filter</button>
    </form>

    <section class="panel table-wrap users-table">
        <table><thead><tr><th>User</th><th>Role and access</th></tr></thead><tbody>
            @foreach($users as $user)
                @php($isMe = $user->is(auth()->user()))
                <tr>
                    <td><strong>{{ $user->name }}</strong>@if($user->name_locked)<span class="chip" title="Corrected here; single sign-on will not change it">name kept</span>@endif<small>{{ $user->email }} · {{ match($user->auth_source) { 'oidc' => 'SSO', 'preview' => 'Preview account', default => 'Local' } }}@if($user->department) · {{ $user->department }}@endif @if($user->network_types) · {{ collect($user->network_types)->map(fn ($key) => \App\Models\NetworkType::labelFor($key))->join(', ') }}@endif</small>@if($user->sso_groups)<span class="group-chips" title="Groups from the last single sign-on">@foreach($user->sso_groups as $group)<span class="chip" title="{{ $group }}">{{ \App\Services\Auth\UserProvisioningService::groupLabel($group) }}</span>@endforeach</span>@endif
                        <div class="row-actions">
                            @if($user->hasTwoFactor())<span class="chip" title="Two-factor sign-in is on">2FA</span>@if(!$isMe)<form method="post" action="{{ route('admin.users.two-factor-reset', $user) }}">@csrf<button class="link-button" data-confirm="Reset two-factor sign-in for {{ $user->name }}? Use this when they've lost their phone; they set it up again at their next sign-in.">Reset 2FA</button></form>@endif @endif
                            @if(!$user->isAdmin() && $user->enabled && !$isMe)<form method="post" action="{{ route('admin.users.impersonate', $user) }}">@csrf<button class="link-button">View as {{ match($user->role) { 'student' => 'this student', 'teacher' => 'this staff member', default => 'this IT user' } }}</button></form>@endif
                            <details class="user-edit"><summary class="link-button">Edit name{{ $user->auth_source === 'oidc' ? '' : ' or email' }}</summary>
                                <div class="fields two">
                                    <label>Name<input name="name" form="user-{{ $user->id }}" value="{{ $user->name }}" required maxlength="120"></label>
                                    @if($user->auth_source === 'oidc')
                                        <p class="hint">The email comes from single sign-on. A corrected name is kept at later sign-ins.</p>
                                    @else
                                        <label>Email<input name="email" type="email" form="user-{{ $user->id }}" value="{{ $user->email }}" required maxlength="255"></label>
                                    @endif
                                </div>
                                <small class="hint">Save with the Save button on this row.</small>
                            </details>
                            @unless($isMe)
                                <form method="post" action="{{ route('admin.users.destroy', $user) }}">@csrf @method('DELETE')<button class="link-button danger-text" data-confirm="Delete {{ $user->name }}? They lose access straight away. Their requests are kept under Deleted user, and the audit log keeps their name. This cannot be undone.">Delete</button></form>
                            @endunless
                        </div>
                    </td>
                    <td><form class="inline-form" id="user-{{ $user->id }}" method="post" action="{{ route('admin.users.update', $user) }}">@csrf @method('PUT')<select name="role" aria-label="Role for {{ $user->name }}">@foreach(['student','teacher','it','admin'] as $role)<option value="{{ $role }}" @selected($user->role===$role)>{{ ucfirst($role) }}</option>@endforeach</select><label class="toggle"><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked($user->enabled)> Enabled</label><label class="toggle" title="Allow sign-in with a password (emergency local sign-in)"><input type="hidden" name="password_login_enabled" value="0"><input type="checkbox" name="password_login_enabled" value="1" @checked($user->password_login_enabled)> Password</label><span>{{ $user->last_login_at?->diffForHumans() ?? 'Never signed in' }}</span><button class="button small secondary">Save</button></form></td>
                </tr>
            @endforeach
        </tbody></table>
        {{ $users->links() }}
    </section>
</x-layouts.app>
