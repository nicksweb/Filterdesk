<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        return view('admin.users', ['users' => User::query()->orderBy('name')->paginate(30)]);
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
            'password' => filled($validated['password'] ?? null) ? Hash::make($validated['password']) : str()->random(64),
            'role' => $validated['role'],
            'enabled' => $request->boolean('enabled'),
            'email_verified_at' => now(),
        ]);
        $audit->log('user.created', $user, ['role' => $user->role, 'local_password' => filled($validated['password'] ?? null)]);

        return back()->with('success', 'User created.');
    }

    public function update(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate(['role' => ['required', 'in:student,teacher,it,admin'], 'enabled' => ['nullable', 'boolean']]);
        abort_if($user->is($request->user()) && (! $request->boolean('enabled') || $validated['role'] !== 'admin'), 422, 'You cannot remove your own administrator access.');
        $user->update(['role' => $validated['role'], 'enabled' => $request->boolean('enabled')]);
        $audit->log('user.updated', $user, ['role' => $user->role, 'enabled' => $user->enabled]);

        return back()->with('success', 'User updated.');
    }
}
