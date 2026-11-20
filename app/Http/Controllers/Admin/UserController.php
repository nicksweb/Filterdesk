<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntegrationConfig;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            'users' => User::query()
                ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query->whereRaw('lower(name) like ?', ["%{$search}%"])->orWhereRaw('lower(email) like ?', ["%{$search}%"])))
                ->when($filters['role'] ?? null, fn ($query, $role) => $query->where('role', $role))
                ->orderBy('name')->paginate(30)->withQueryString(),
            'filters' => $filters,
            'counts' => User::query()->selectRaw('role, count(*) as total')->groupBy('role')->pluck('total', 'role'),
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
        $validated = $request->validate(['role' => ['required', 'in:student,teacher,it,admin'], 'enabled' => ['nullable', 'boolean'], 'password_login_enabled' => ['nullable', 'boolean']]);
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
        $user->update(['role' => $validated['role'], 'enabled' => $request->boolean('enabled'), 'password_login_enabled' => $passwordLogin]);
        $audit->log('user.updated', $user, ['role' => $user->role, 'enabled' => $user->enabled, 'password_login_enabled' => $user->password_login_enabled]);

        return back()->with('success', 'User updated.');
    }
}
