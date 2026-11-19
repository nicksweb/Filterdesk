<?php

namespace App\Services\Policy;

use App\Services\Domains\PublicSuffixList;
use App\Services\Network\DomainRules;

/**
 * Builds the hostname set IT should allow for a request.
 *
 * DNS filtering cannot see URL paths, and most sites need more than the one
 * hostname a teacher typed: the bare registrable domain, www, and any CDN,
 * API or sign-in hosts the page loads. Hosts observed during a website check
 * that failed on any network are recommended; hosts that loaded everywhere
 * are listed but not selected.
 */
class DomainRecommender
{
    public function __construct(
        private readonly PublicSuffixList $suffixes,
        private readonly PolicyListParser $parser,
    ) {}

    /**
     * @param  list<string>  $requested
     * @param  list<array{host: string, failed_on?: list<string>}>  $related
     * @param  list<string>  $alreadyAllowed  Hostnames already allowed for the target audience.
     * @return list<array{domain: string, kind: string, reason: string, selected: bool}>
     */
    public function recommend(array $requested, array $related, DomainRules $rules, array $alreadyAllowed = []): array
    {
        $items = [];
        $add = function (string $domain, string $kind, string $reason, bool $selected) use (&$items): void {
            $domain = $this->parser->normalise($domain);
            if (! $this->parser->valid($domain) || $this->suffixes->isPublicSuffix($domain)) {
                return;
            }
            if (isset($items[$domain])) {
                $items[$domain]['selected'] = $items[$domain]['selected'] || $selected;

                return;
            }
            $items[$domain] = compact('domain', 'kind', 'reason', 'selected');
        };

        $requestedApexes = [];
        foreach ($requested as $host) {
            $add($host, 'requested', 'Requested hostname.', true);
            $apex = $this->suffixes->registrableDomain($host);
            if ($apex === null) {
                continue;
            }
            $requestedApexes[$apex] = true;
            if ($apex !== $host) {
                $add($apex, 'apex', 'Registrable domain. DNS filtering cannot see paths, and sites often redirect between the bare domain and its subdomains.', true);
            } else {
                $add("www.{$apex}", 'www', 'Common www alias of the requested domain.', true);
            }
        }

        foreach ($related as $observed) {
            $host = (string) ($observed['host'] ?? '');
            $failedOn = $observed['failed_on'] ?? [];
            $apex = $this->suffixes->registrableDomain($host);
            $sameSite = $apex !== null && isset($requestedApexes[$apex]);
            $failed = $failedOn !== [];
            $where = $failed ? ' Failed on: '.implode(', ', array_map('ucfirst', $failedOn)).'.' : ' Loaded on every network.';

            $add(
                $host,
                $sameSite ? 'subdomain' : 'third_party',
                ($sameSite ? 'Subdomain loaded by the page.' : 'Third-party host loaded by the page (CDN, fonts, API or sign-in).').$where,
                $failed,
            );
        }

        foreach ($items as $domain => $item) {
            foreach ($alreadyAllowed as $allowed) {
                if ($rules->covers($allowed, $domain)) {
                    $items[$domain]['selected'] = false;
                    $items[$domain]['reason'] .= " Already allowed by {$allowed}.";

                    break;
                }
            }
        }

        return array_values($items);
    }

    /**
     * Remove entries already covered by another entry when the provider
     * matches subdomains. With exact matching every entry is kept.
     *
     * @param  list<string>  $domains
     * @return list<string>
     */
    public function compact(array $domains, DomainRules $rules): array
    {
        $domains = array_values(array_unique($domains));
        sort($domains);

        if (! $rules->entriesIncludeSubdomains) {
            return $domains;
        }

        return array_values(array_filter($domains, function (string $domain) use ($domains): bool {
            foreach ($domains as $other) {
                if ($other !== $domain && str_ends_with($domain, '.'.$other)) {
                    return false;
                }
            }

            return true;
        }));
    }
}
