<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Lets an administrator see the app exactly as a staff member or student
 * does. Admin accounts cannot be impersonated, the session remembers the
 * real administrator, and every audit event records them.
 */
class ImpersonationController extends Controller
{
    public const SESSION_KEY = 'impersonator_id';

    /** Roles that can be previewed with a built-in account. */
    public const PREVIEW_ROLES = ['student' => 'Student', 'teacher' => 'Staff', 'it' => 'IT staff'];

    public function start(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        $admin = $request->user();
        abort_if($request->session()->has(self::SESSION_KEY), 409, 'Return to your own account first.');
        abort_if($user->isAdmin() || $user->is($admin), 403, 'Administrator accounts cannot be impersonated.');
        abort_unless($user->enabled, 403, 'Disabled accounts cannot be impersonated.');

        return $this->switchTo($request, $admin, $user, $audit);
    }

    /** View as a built-in preview account for a role, creating it on first use. */
    public function preview(Request $request, string $role, AuditLogger $audit): RedirectResponse
    {
        abort_unless(array_key_exists($role, self::PREVIEW_ROLES), 404);
        abort_if($request->session()->has(self::SESSION_KEY), 409, 'Return to your own account first.');

        $user = User::query()->firstOrCreate(
            ['email' => "preview-{$role}@preview.invalid"],
            ['name' => 'Preview '.self::PREVIEW_ROLES[$role], 'role' => $role, 'enabled' => true, 'auth_source' => 'preview',
                'notify_new_requests' => false, 'password' => Hash::make(Str::random(64))],
        );
        $user->forceFill(['role' => $role, 'enabled' => true])->save();

        return $this->switchTo($request, $request->user(), $user, $audit);
    }

    /**
     * Submit a request for someone: switch to them and open the request
     * form. Someone who has never signed in gets an account now, without a
     * password; single sign-on takes it over by email when they first sign in.
     */
    public function onBehalf(Request $request, AuditLogger $audit): RedirectResponse
    {
        abort_if($request->session()->has(self::SESSION_KEY), 409, 'Return to your own account first.');
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:120'],
            'role' => ['required', 'in:student,teacher'],
        ]);
        $email = strtolower(trim($validated['email']));
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();
        if ($user && ($user->isAdmin() || $user->is($request->user()))) {
            return back()->withInput()->with('error', 'Administrator accounts cannot be used. Submit the request as yourself instead.');
        }
        if ($user && ! $user->enabled) {
            return back()->withInput()->with('error', "{$user->name}'s account is disabled. Enable it first.");
        }
        if (! $user) {
            $name = trim((string) ($validated['name'] ?? '')) ?: Str::of(Str::before($email, '@'))->replace(['.', '_', '-'], ' ')->title()->value();
            $user = User::query()->create([
                'name' => $name, 'email' => $email, 'role' => $validated['role'], 'enabled' => true,
                'auth_source' => 'local', 'password_login_enabled' => false, 'notify_new_requests' => false,
                'password' => Hash::make(Str::random(64)),
            ]);
            $audit->log('user.created_for_request', $user, ['role' => $user->role, 'by' => $request->user()->id]);
        }

        $this->switchTo($request, $request->user(), $user, $audit);

        return redirect()->route('requests.create')->with('success', "You're submitting a request for {$user->name} ({$user->email}). It's recorded as theirs, sent by you, and they're emailed about it. Use Return to my account when you're done.");
    }

    public function stop(Request $request, AuditLogger $audit): RedirectResponse
    {
        $impersonatorId = $request->session()->pull(self::SESSION_KEY);
        $admin = $impersonatorId ? User::query()->find($impersonatorId) : null;
        abort_unless($admin && $admin->isAdmin() && $admin->enabled, 403);

        $viewed = $request->user();
        Auth::login($admin);
        $request->session()->regenerate();
        $audit->log('user.impersonation_ended', $viewed, ['impersonator_id' => $admin->id]);

        return redirect()->route('admin.users.index')->with('success', "Back to your own account. You were viewing as {$viewed?->name}.");
    }

    private function switchTo(Request $request, User $admin, User $user, AuditLogger $audit): RedirectResponse
    {
        $audit->log('user.impersonation_started', $user, ['role' => $user->role, 'impersonator_id' => $admin->id]);
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, $admin->id);

        return redirect()->route('dashboard')->with('success', "You are now viewing as {$user->name} ({$user->role}). Anything you submit is recorded as them, with you as the real actor in the audit log.");
    }
}
