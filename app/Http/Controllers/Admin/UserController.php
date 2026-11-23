<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntegrationConfig;
use App\Models\User;
use App\Models\WebsiteRequest;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'role' => ['nullable', 'in:student,teacher,it,admin']]);
        $search = strtolower(trim($filters['q'] ?? ''));

        return view('admin.users', [
            'users' => User::query()->where('auth_source', '!=', 'deleted')
                ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query->whereRaw('lower(name) like ?', ["%{$search}%"])->orWhereRaw('lower(email) like ?', ["%{$search}%"])))
                ->when($filters['role'] ?? null, fn ($query, $role) => $query->where('role', $role))
                ->orderBy('name')->paginate(30)->withQueryString(),
            'filters' => $filters,
            'counts' => User::query()->where('auth_source', '!=', 'deleted')->selectRaw('role, count(*) as total')->groupBy('role')->pluck('total', 'role'),
        ]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', 'in:student,teacher,it,admin'],
            'password' => ['nullable', 'string', 'min:14', 'confirmed'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => strtolower($validated['email']),
            'password' => filled($validated['password'] ?? null) ? Hash::make($validated['password']) : Hash::make(str()->random(64)),
            'role' => $validated['role'],
            'enabled' => $request->boolean('enabled'),
            'email_verified_at' => now(),
        ]);
        $audit->log('user.created', $user, ['role' => $user->role, 'local_password' => filled($validated['password'] ?? null)]);

        return back()->with('success', 'User created.');
    }

    public function update(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        abort_if($user->isDeletedPlaceholder(), 404);
        $validated = $request->validate([
            'role' => ['required', 'in:student,teacher,it,admin'], 'enabled' => ['nullable', 'boolean'], 'password_login_enabled' => ['nullable', 'boolean'],
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ]);
        $passwordLogin = $request->boolean('password_login_enabled', $user->password_login_enabled);
        if ($user->is($request->user()) && ! $passwordLogin && $user->password_login_enabled && ! IntegrationConfig::query()->where('provider', 'oidc')->where('enabled', true)->exists()) {
            return back()->with('error', 'Single sign-on is not enabled, so turning off your own password sign-in would lock you out.');
        }
        if ($user->is($request->user()) && (! $request->boolean('enabled') || $validated['role'] !== 'admin')) {
            return back()->with('error', 'You cannot remove your own administrator access.');
        }

        $remainingAdmins = User::query()->where('role', 'admin')->where('enabled', true)->whereKeyNot($user->id)->count();
        if ($user->isAdmin() && $remainingAdmins === 0 && (! $request->boolean('enabled') || $validated['role'] !== 'admin')) {
            return back()->with('error', 'At least one enabled administrator must remain.');
        }
        $details = [];
        if (array_key_exists('name', $validated) && $validated['name'] !== $user->name) {
            $details['name'] = ['from' => $user->name, 'to' => $validated['name']];
            // Single sign-on would put the old name back at the next sign-in; keep the corrected one.
            $user->name_locked = $user->auth_source === 'oidc' || $user->name_locked;
            $user->name = $validated['name'];
        }
        // Single sign-on users' email comes from the provider at every sign-in.
        if (array_key_exists('email', $validated) && $user->auth_source !== 'oidc' && strtolower($validated['email']) !== $user->email) {
            $details['email'] = ['from' => $user->email, 'to' => strtolower($validated['email'])];
            $user->email = strtolower($validated['email']);
        }
        $user->fill(['role' => $validated['role'], 'enabled' => $request->boolean('enabled'), 'password_login_enabled' => $passwordLogin])->save();
        $audit->log('user.updated', $user, ['role' => $user->role, 'enabled' => $user->enabled, 'password_login_enabled' => $user->password_login_enabled] + $details);

        return back()->with('success', "{$user->name} updated.");
    }

    /**
     * Delete a user. Their requests stay, under "Deleted user", so the
     * history of what was asked and decided is kept; the audit log records
     * who they were. Policy entries, reviews and approvals they touched keep
     * working without them.
     */
    public function destroy(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        abort_if($user->isDeletedPlaceholder(), 404);
        if ($user->is($request->user())) {
            return back()->with('error', 'You cannot delete your own account.');
        }
        if ($user->isAdmin() && $user->enabled && User::query()->where('role', 'admin')->where('enabled', true)->whereKeyNot($user->id)->doesntExist()) {
            return back()->with('error', 'At least one enabled administrator must remain.');
        }

        $requests = DB::transaction(function () use ($user): int {
            $placeholder = User::deletedPlaceholder();
            $moved = WebsiteRequest::query()->where('requester_id', $user->id)->update(['requester_id' => $placeholder->id]);
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->delete();

            return $moved;
        });
        $audit->log('user.deleted', null, ['name' => $user->name, 'email' => $user->email, 'role' => $user->role, 'requests' => $requests]);

        return redirect()->route('admin.users.index')->with('success', "{$user->name} was deleted.".($requests ? " Their {$requests} request(s) are kept under Deleted user." : ''));
    }
}
