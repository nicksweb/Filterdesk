<?php

namespace App\Http\Controllers;

use App\Models\IntegrationConfig;
use App\Services\Auth\UserProvisioningService;
use App\Services\Oidc\OidcClient;
use App\Services\SettingsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
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

    public function local(Request $request): RedirectResponse
    {
        abort_unless(app(SettingsRepository::class)->get('auth.emergency_local_login', true), 404);
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);

        if (! Auth::attempt($credentials + ['enabled' => true], $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'The supplied credentials are invalid.'])->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function oidcRedirect(Request $request, OidcClient $client): RedirectResponse
    {
        [$url, $state] = $client->authorizationUrl();
        $request->session()->put('oidc.state', $state);

        return redirect()->away($url);
    }

    public function oidcCallback(Request $request, OidcClient $client, UserProvisioningService $users): RedirectResponse
    {
        if (! hash_equals((string) $request->session()->pull('oidc.state'), (string) $request->query('state'))) {
            return redirect()->route('login')->withErrors(['email' => 'The sign-in session expired. Please try again.']);
        }

        try {
            $user = $users->provision($client->claims($client->exchange((string) $request->query('code'))));
            Auth::login($user, true);
            $request->session()->regenerate();

            return redirect()->intended(route('dashboard'));
        } catch (Throwable $exception) {
            Log::warning('OIDC sign-in failed', ['message' => $exception->getMessage()]);

            return redirect()->route('login')->withErrors(['email' => 'Single sign-on failed. Contact IT if this continues.']);
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
