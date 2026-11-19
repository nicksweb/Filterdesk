<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        return view('admin.users', ['users' => User::query()->orderBy('name')->paginate(30)]);
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
