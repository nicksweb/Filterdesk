<?php

namespace App\Services\Oidc;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OidcDiscoveryService
{
    /** @return array<string, mixed> */
    public function discover(string $issuer): array
    {
        $issuer = rtrim($issuer, '/');

        return Cache::remember('oidc.discovery.'.sha1($issuer), 3600, function () use ($issuer): array {
            $response = Http::connectTimeout(3)->timeout(8)->get("{$issuer}/.well-known/openid-configuration");
            $response->throw();
            $document = $response->json();

            foreach (['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint'] as $required) {
                if (empty($document[$required]) || ! str_starts_with($document[$required], 'https://')) {
                    throw new RuntimeException("The OIDC discovery document is missing a secure {$required}.");
                }
            }

            return $document;
        });
    }
}
