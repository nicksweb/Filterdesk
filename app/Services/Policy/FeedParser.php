<?php

namespace App\Services\Policy;

use App\Services\Domains\PublicSuffixList;

/**
 * Reads one line of a third-party list. Understands plain domains, hosts
 * files ("0.0.0.0 example.com"), adblock rules ("||example.com^"),
 * wildcards ("*.example.com"), and for IP lists single addresses or CIDR
 * subnets. Comments, exceptions and anything unrecognised are skipped.
 */
class FeedParser
{
    public function __construct(
        private readonly PolicyListParser $parser,
        private readonly PublicSuffixList $suffixes,
    ) {}

    public function domain(string $line): ?string
    {
        $line = trim(preg_replace('/\s+#.*$/', '', $line) ?? '');
        if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '!') || str_starts_with($line, '@@') || str_starts_with($line, '[')) {
            return null;
        }

        if (str_starts_with($line, '||')) {
            $line = (string) preg_replace('/[\^$\/].*$/', '', substr($line, 2));
        } elseif (preg_match('/^(?:0\.0\.0\.0|127\.0\.0\.1|::1?|::)\s+(\S+)/', $line, $match)) {
            $line = $match[1];
        } elseif (preg_match('/\s/', $line)) {
            return null;
        }

        $domain = $this->parser->normalise($line);
        if (! $this->parser->valid($domain) || ! str_contains($domain, '.') || $this->suffixes->isPublicSuffix($domain) || in_array($domain, ['localhost', 'localhost.localdomain'], true)) {
            return null;
        }

        return $domain;
    }

    /** A single IP or subnet of the given family ("ipv4" or "ipv6"), normalised. */
    public function ip(string $line, string $family): ?string
    {
        $line = trim(preg_replace('/[\s;#].*$/', '', trim($line)) ?? '');
        if ($line === '') {
            return null;
        }

        [$address, $prefix] = array_pad(explode('/', $line, 2), 2, null);
        $flag = $family === 'ipv6' ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4;
        if (filter_var($address, FILTER_VALIDATE_IP, $flag) === false) {
            return null;
        }
        $address = inet_ntop((string) inet_pton($address)) ?: $address;

        if ($prefix === null) {
            return $address;
        }
        $max = $family === 'ipv6' ? 128 : 32;
        if (! ctype_digit($prefix) || (int) $prefix < 1 || (int) $prefix > $max) {
            return null;
        }

        return (int) $prefix === $max ? $address : "{$address}/{$prefix}";
    }
}
