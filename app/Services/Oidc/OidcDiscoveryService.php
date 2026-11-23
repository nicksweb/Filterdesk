<?php

namespace App\Services\Oidc;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Reads a provider's discovery document. Works with Entra ID, Google,
 * CloudWork ID and other standard providers: only the authorization and
 * token endpoints are required; userinfo is used when the provider has one.
 */
class OidcDiscoveryService
{
    /** The issuer as typed, without a pasted /.well-known/openid-configuration or trailing slash. */
    public static function normalise(string $issuer): string
    {
        $issuer = trim($issuer);
        $issuer = preg_replace('#/\.well-known/openid-configuration/?$#', '', $issuer) ?? $issuer;

        return rtrim($issuer, '/');
    }

    /** @return array<string, mixed> */
    public function discover(string $issuer): array
    {
        $issuer = self::normalise($issuer);

        return Cache::remember('oidc.discovery.'.sha1($issuer), 3600, function () use ($issuer): array {
            $response = Http::connectTimeout(3)->timeout(8)->acceptJson()->get("{$issuer}/.well-known/openid-configuration");
            $response->throw();
            $document = $response->json();
            if (! is_array($document)) {
                throw new RuntimeException('The discovery document is not JSON.');
            }

            foreach (['authorization_endpoint', 'token_endpoint'] as $required) {
                if (empty($document[$required]) || ! str_starts_with((string) $document[$required], 'https://')) {
                    throw new RuntimeException("The OIDC discovery document is missing a secure {$required}.");
                }
            }
            if (filled($document['userinfo_endpoint'] ?? null) && ! str_starts_with((string) $document['userinfo_endpoint'], 'https://')) {
                throw new RuntimeException('The OIDC discovery document has an insecure userinfo_endpoint.');
            }

            return $document;
        });
    }

    /**
     * Discover for a settings form: a readable validation error instead of a
     * server error when the issuer is wrong or unreachable.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function check(string $issuer, string $field): array
    {
        try {
            return $this->discover($issuer);
        } catch (Throwable $exception) {
            $url = self::normalise($issuer).'/.well-known/openid-configuration';
            $reason = match (true) {
                $exception instanceof RequestException => 'it answered HTTP '.$exception->response->status().'.',
                $exception instanceof RuntimeException => $exception->getMessage(),
                default => 'it could not be reached ('.str($exception->getMessage())->limit(120).').',
            };

            throw ValidationException::withMessages([$field => "Could not read the provider's settings from {$url}: {$reason} Check the issuer URL (CloudWork ID: the issuer shown under Single Sign On → Manage OpenID Connect Apps → Identity Provider)."]);
        }
    }
}
