<?php

namespace App\Http\Controllers;

use App\Models\IntegrationConfig;
use App\Models\User;
use App\Notifications\AccountLockedNotification;
use App\Services\AuditLogger;
use App\Services\Auth\LoginThrottle;
use App\Services\Auth\UserProvisioningService;
use App\Services\Oidc\OidcClient;
use App\Services\SettingsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\View\View;
use Throwable;

class AuthController extends Controller
{
    public function login(): View
    {
        return view('auth.login', [
            'oidcEnabled' => IntegrationConfig::query()->where('provider', 'oidc')->where('enabled', true)->exists(),
            'localEnabled' => (bool) app(SettingsRepository::class)->get('auth.emergency_local_login', true),
        ]);
    }

    public function local(Request $request, LoginThrottle $throttle, AuditLogger $audit): RedirectResponse
    {
        abort_unless(app(SettingsRepository::class)->get('auth.emergency_local_login', true), 404);
        $credentials = $request->validate(['email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string', 'max:500']]);
        $credentials['email'] = strtolower(trim($credentials['email']));

        if ($seconds = $throttle->blockedFor($request)) {
            $audit->log('auth.login_blocked', null, ['email' => $credentials['email']]);

            return back()->withErrors(['email' => 'Too many failed sign-in attempts. Try again in '.max(1, (int) ceil($seconds / 60)).' minute(s).'])->onlyInput('email');
        }

        if (! Auth::attempt($credentials + ['enabled' => true, 'sign_in_allowed' => fn ($query) => $query->where('password_login_enabled', true)], $request->boolean('remember'))) {
            $locked = $throttle->failed($request);
            $audit->log($locked ? 'auth.account_locked' : 'auth.login_failed', User::query()->where('email', $credentials['email'])->first(), ['email' => $credentials['email']]);
            if ($locked) {
                $this->alertLocked($credentials['email'], (string) $request->ip());
            }

            return back()->withErrors(['email' => 'The supplied credentials are invalid.'])->onlyInput('email');
        }

        $throttle->succeeded($request);
        $request->session()->regenerate();
        $request->user()->forceFill(['last_login_at' => now()])->save();
        $audit->log('auth.login', $request->user(), ['method' => 'password']);

        return redirect()->intended(route('dashboard'));
    }

    public function oidcRedirect(Request $request, OidcClient $client): RedirectResponse
    {
        try {
            [$url, $state, $pkce] = $client->authorizationUrl();
        } catch (Throwable $exception) {
            OidcClient::recordFailure($exception);

            return redirect()->route('login')->withErrors(['email' => 'Single sign-on is not available right now. Use the emergency sign-in, or contact IT.']);
        }
        $request->session()->put('oidc.state', $state);
        $request->session()->put('oidc.pkce', $pkce);

        return redirect()->away($url);
    }

    public function oidcCallback(Request $request, OidcClient $client, UserProvisioningService $users): RedirectResponse
    {
        if (! hash_equals((string) $request->session()->pull('oidc.state'), (string) $request->query('state'))) {
            return redirect()->route('login')->withErrors(['email' => 'The sign-in session expired. Please try again.']);
        }

        try {
            if (filled($request->query('error'))) {
                throw new \RuntimeException('The identity provider returned '.$request->query('error').': '.str((string) $request->query('error_description'))->limit(300));
            }
            $user = $users->provision($client->claims($client->exchange((string) $request->query('code'), $request->session()->pull('oidc.pkce'))));
            Auth::login($user, true);
            $request->session()->regenerate();

            return redirect()->intended(route('dashboard'));
        } catch (Throwable $exception) {
            Log::warning('OIDC sign-in failed', ['message' => $exception->getMessage()]);
            OidcClient::recordFailure($exception);

            return redirect()->route('login')->withErrors(['email' => 'Single sign-on failed. Contact IT if this continues.']);
        }
    }

    /** Tell the account owner and IT that an account was locked. */
    private function alertLocked(string $email, string $ip): void
    {
        $notification = new AccountLockedNotification($email, $ip, (int) (LoginThrottle::DECAY_SECONDS / 60));
        try {
            $user = User::query()->where('email', $email)->where('enabled', true)->first();
            $user?->notify($notification);
            $address = app(SettingsRepository::class)->get('notifications.it_address');
            if (filled($address) && strcasecmp($address, $email) !== 0) {
                Notification::route('mail', $address)->notify($notification);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
