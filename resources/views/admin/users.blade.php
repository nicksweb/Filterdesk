<x-layouts.app title="Users">
    <x-slot:head><link rel="stylesheet" href="{{ asset('admin.css') }}"></x-slot:head>
    <div class="page-heading">
        <div><p class="eyebrow">Administration</p><h1>User management</h1><p>Create local test accounts or pre-stage users before OIDC is connected.</p></div>
    </div>

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

    <section class="panel table-wrap users-table">
        <table><thead><tr><th>User</th><th>Role and access</th></tr></thead><tbody>
            @foreach($users as $user)
                <tr><td><strong>{{ $user->name }}</strong><small>{{ $user->email }}</small></td><td><form class="inline-form" method="post" action="{{ route('admin.users.update', $user) }}">@csrf @method('PUT')<select name="role">@foreach(['student','teacher','it','admin'] as $role)<option value="{{ $role }}" @selected($user->role===$role)>{{ ucfirst($role) }}</option>@endforeach</select><label class="toggle"><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked($user->enabled)> Enabled</label><span>{{ $user->last_login_at?->diffForHumans() ?? 'Never signed in' }}</span><button class="button small secondary">Save</button></form></td></tr>
            @endforeach
        </tbody></table>
        {{ $users->links() }}
    </section>
</x-layouts.app>
