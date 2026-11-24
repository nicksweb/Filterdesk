<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Auth\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Two-factor sign-in: set it up on the profile page (scan or type the key
 * into an authenticator app, confirm with a code, keep the recovery codes),
 * the second step at sign-in, and an admin resetting someone's.
 */
class TwoFactorController extends Controller
{
    public const PENDING_LOGIN = 'two_factor.login';

    private const PENDING_SECRET = 'two_factor.secret';

    public function show(Request $request, TwoFactor $twoFactor): View
    {
        $user = $request->user();
        $secret = $request->session()->get(self::PENDING_SECRET);

        return view('auth.two-factor', [
            'user' => $user,
            'secret' => $secret,
            'uri' => $secret ? $twoFactor->uri($user, $secret) : null,
            'required' => $twoFactor->requiredFor($user, (string) $request->session()->get('auth.method', $user->auth_source === 'oidc' ? 'oidc' : 'password')),
            'remaining' => $twoFactor->remainingRecoveryCodes($user),
        ]);
    }

    public function start(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        $request->session()->put(self::PENDING_SECRET, $twoFactor->newSecret());

        return redirect()->route('profile.two-factor');
    }

    public function confirm(Request $request, TwoFactor $twoFactor, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $secret = (string) $request->session()->get(self::PENDING_SECRET);
        $user = $request->user();
        if ($secret === '' || ! $twoFactor->verifyCode($secret, $validated['code'], $user)) {
            return back()->withErrors(['code' => 'That code is not right. Check the time on your phone is set automatically, and enter the code the app shows now.']);
        }

        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();
        $codes = $twoFactor->newRecoveryCodes($user);
        $request->session()->forget(self::PENDING_SECRET);
        $audit->log('auth.two_factor_enabled', $user);

        return redirect()->route('profile.two-factor')->with('success', 'Two-factor sign-in is on.')->with('recovery_codes', $codes);
    }

    public function recoveryCodes(Request $request, TwoFactor $twoFactor, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $user = $request->user();
        if (! $twoFactor->attempt($user, $validated['code'])['ok']) {
            return back()->withErrors(['code' => 'That code is not right.']);
        }
        $codes = $twoFactor->newRecoveryCodes($user);
        $audit->log('auth.two_factor_recovery_codes_renewed', $user);

        return back()->with('success', 'New recovery codes made. The old ones no longer work.')->with('recovery_codes', $codes);
    }

    public function disable(Request $request, TwoFactor $twoFactor, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $user = $request->user();
        if (! $twoFactor->attempt($user, $validated['code'])['ok']) {
            return back()->withErrors(['code' => 'That code is not right.']);
        }
        $twoFactor->disable($user);
        $audit->log('auth.two_factor_disabled', $user);

        return back()->with('success', 'Two-factor sign-in is off.');
    }

    /** The second step at sign-in, after the password (or single sign-on). */
    public function challenge(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has(self::PENDING_LOGIN)) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    public function verify(Request $request, TwoFactor $twoFactor, AuditLogger $audit): RedirectResponse|Response
    {
        $pending = (array) $request->session()->get(self::PENDING_LOGIN, []);
        $user = isset($pending['user_id']) ? User::query()->where('enabled', true)->find($pending['user_id']) : null;
        // A sign-in left waiting longer than 10 minutes starts again.
        if (! $user || now()->timestamp - (int) ($pending['at'] ?? 0) > 600) {
            $request->session()->forget(self::PENDING_LOGIN);

            return redirect()->route('login')->withErrors(['email' => 'The sign-in took too long. Please start again.']);
        }

        $validated = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $result = $twoFactor->attempt($user, $validated['code']);
        if (! $result['ok']) {
            $audit->log('auth.two_factor_failed', $user);

            return back()->withErrors(['code' => isset($result['locked'])
                ? 'Too many wrong codes. Try again in '.max(1, (int) ceil($result['locked'] / 60)).' minute(s), or ask an admin to reset your two-factor sign-in.'
                : 'That code is not right. Enter the 6-digit code your app shows now, or a recovery code.']);
        }

        $request->session()->forget(self::PENDING_LOGIN);
        Auth::login($user, (bool) ($pending['remember'] ?? false));
        $request->session()->regenerate();
        $request->session()->put('auth.method', $pending['method'] ?? 'password');
        $user->forceFill(['last_login_at' => now()])->save();
        $audit->log('auth.login', $user, ['method' => $pending['method'] ?? 'password', 'two_factor' => isset($result['recovery']) ? 'recovery_code' : 'app']);

        if (($pending['popup'] ?? false)) {
            return response()->view('auth.sso-done', ['after' => route('dashboard')]);
        }

        $redirect = redirect()->intended(route('dashboard'));

        return isset($result['recovery'])
            ? $redirect->with('success', 'Signed in with a recovery code. '.$twoFactor->remainingRecoveryCodes($user).' left: make new ones under Profile → Two-factor sign-in.')
            : $redirect;
    }

    /** An admin turns off someone's two-factor sign-in (a lost phone); they set it up again at their next sign-in. */
    public function reset(Request $request, User $user, TwoFactor $twoFactor, AuditLogger $audit): RedirectResponse
    {
        $twoFactor->disable($user);
        $audit->log('auth.two_factor_reset', $user, ['by' => $request->user()->id]);

        return back()->with('success', "Two-factor sign-in reset for {$user->name}. They set it up again when they next sign in.");
    }

    /**
     * Hold a sign-in until the second step is done.
     *
     * @param  array{method: string, remember?: bool, popup?: bool}  $context
     */
    public static function hold(Request $request, User $user, array $context): RedirectResponse
    {
        $request->session()->regenerate();
        $request->session()->put(self::PENDING_LOGIN, ['user_id' => $user->id, 'at' => now()->timestamp] + $context);

        return redirect()->route('two-factor.challenge');
    }
}
