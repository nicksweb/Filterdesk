<?php

namespace App\Services\Oidc;

/**
 * What a known identity provider needs before it sends groups or roles, so
 * group: role rules can match: CloudWork ID only sends them with its profile
 * scope; Entra ID needs a groups claim or app roles on the app registration;
 * Google sends no groups at all. Other providers are judged from their
 * discovery document (a scope or claim named for groups or roles).
 */
class OidcProviderHints
{
    public const CLOUDWORK_SCOPE = 'https://scopes.cloudwork.id/profile';

    /**
     * @param  array<string, mixed>|null  $discovery
     * @return array{provider: ?string, scopes: list<string>, note: ?string}
     */
    public function for(string $issuer, ?array $discovery = null): array
    {
        $host = strtolower((string) parse_url($issuer, PHP_URL_HOST));
        $scopesSupported = array_map('strval', (array) ($discovery['scopes_supported'] ?? []));
        $ends = fn (string ...$suffixes): bool => collect($suffixes)->contains(fn (string $suffix) => $host === $suffix || str_ends_with($host, '.'.$suffix));

        if ($ends('cloudworkengine.net', 'cloudwork.id', 'cloudwork.com') || in_array(self::CLOUDWORK_SCOPE, $scopesSupported, true)) {
            return ['provider' => 'CloudWork ID', 'scopes' => [self::CLOUDWORK_SCOPE],
                'note' => 'CloudWork ID only sends groups and roles when the app asks for its profile scope ('.self::CLOUDWORK_SCOPE.').'];
        }
        if ($ends('microsoftonline.com', 'windows.net', 'microsoftonline.us')) {
            return ['provider' => 'Microsoft Entra ID', 'scopes' => [],
                'note' => 'Entra ID sends groups only when the app registration has a groups claim (Token configuration → Add groups claim) or app roles (App roles, assigned under Enterprise applications). Group values are object IDs unless you choose another format.'];
        }
        if ($ends('accounts.google.com', 'google.com')) {
            return ['provider' => 'Google', 'scopes' => [], 'note' => 'Google does not send groups. Use email_domain: rules, or set roles by hand under Users.'];
        }

        // Any other provider: a non-standard scope named for groups or roles is probably what sends them.
        $named = array_values(array_filter($scopesSupported, fn (string $scope) => (bool) preg_match('/(^|[\/:._-])(groups?|roles?)$/i', $scope)));

        return ['provider' => null, 'scopes' => $named, 'note' => $named !== [] ? 'The provider offers '.implode(', ', $named).', which probably carries groups or roles.' : null];
    }

    /**
     * A warning when group: rules cannot match: the provider needs a scope
     * that is not requested, or it advertises no groups or roles claim.
     *
     * @param  list<array{type: string, value: string, role: string}>  $rules
     * @param  list<string>  $scopes  the extra scopes that will be requested
     * @param  array<string, mixed>|null  $discovery
     */
    public function warning(string $issuer, array $rules, array $scopes, ?array $discovery = null): ?string
    {
        if (! collect($rules)->contains(fn (array $rule) => $rule['type'] === 'group')) {
            return null;
        }
        $hint = $this->for($issuer, $discovery);
        $missing = array_values(array_diff($hint['scopes'], $scopes));
        if ($missing !== [] && $hint['provider'] !== null) {
            return 'None of your group: rules can match yet: '.$hint['note'].' Add it under Extra scopes.';
        }
        if ($hint['provider'] === 'Google') {
            return 'None of your group: rules can match: '.$hint['note'];
        }
        $claims = array_map('strval', (array) ($discovery['claims_supported'] ?? []));
        if ($hint['provider'] === null && $claims !== [] && $scopes === [] && ! collect($claims)->contains(fn (string $claim) => (bool) preg_match('/(^|[\/:._-])(groups?|roles?)$/i', $claim))) {
            return 'The provider lists no groups or roles claim, so group: rules may not match. Use Test sign-in to see what it sends.';
        }

        return null;
    }
}
