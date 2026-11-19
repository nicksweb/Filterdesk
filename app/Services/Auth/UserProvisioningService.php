<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Oidc\OidcClient;
use Illuminate\Auth\Access\AuthorizationException;

class UserProvisioningService
{
    public function __construct(private readonly OidcClient $oidc) {}

    /** @param array{sub: string, email: string, name: string} $claims */
    public function provision(array $claims): User
    {
        $integration = $this->oidc->integration();
        $allowed = array_filter(array_map('trim', $integration->configuration['allowed_domains'] ?? []));
        $domain = str($claims['email'])->afterLast('@')->lower()->toString();

        if ($allowed !== [] && ! in_array($domain, $allowed, true)) {
            throw new AuthorizationException('Your email domain is not permitted to use this service.');
        }

        $user = User::query()->where('oidc_subject', $claims['sub'])->orWhere('email', $claims['email'])->first();
        $user ??= new User(['email' => $claims['email'], 'role' => 'teacher']);
        $user->fill(['name' => $claims['name'], 'email' => $claims['email'], 'oidc_subject' => $claims['sub'], 'last_login_at' => now()]);
        $user->save();

        if (! $user->enabled) {
            throw new AuthorizationException('This account has been disabled.');
        }

        return $user;
    }
}
