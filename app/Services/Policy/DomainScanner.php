<?php

namespace App\Services\Policy;

use App\Services\Domains\PublicSuffixList;
use App\Services\Website\WebsiteChecker;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Finds every hostname a site uses so IT can allow or block it as a whole.
 *
 * Two sources: the hosts a real browser loads on each network probe (CDNs,
 * APIs, sign-in, fonts), and optionally the subdomains named in public
 * certificate-transparency logs, which reveal hosts a single page load never
 * touches (apps, game servers, regional mirrors).
 */
class DomainScanner
{
    private const CERTIFICATE_LIMIT = 300;

    public function __construct(
        private readonly WebsiteChecker $checker,
        private readonly PublicSuffixList $suffixes,
        private readonly PolicyListParser $parser,
        private readonly PolicyEngine $engine,
    ) {}

    /**
     * @param  list<array{url: string, hostname: string}>  $targets
     * @return array{hosts: list<array{host: string, apex: ?string, origin: string, same_site: bool, failed_on: list<string>, loaded: bool, listed: list<array{list: string, kind: string}>}>, apexes: list<string>, notes: list<string>, duration_ms: int}
     */
    public function scan(array $targets, bool $includeCertificates): array
    {
        $started = hrtime(true);
        $notes = [];
        $hosts = [];
        $apexes = [];

        $add = function (string $host, string $origin, array $failedOn = [], bool $loaded = false) use (&$hosts): void {
            $host = $this->parser->normalise($host);
            if (! $this->parser->valid($host) || $this->suffixes->isPublicSuffix($host)) {
                return;
            }
            $hosts[$host] ??= ['host' => $host, 'origin' => $origin, 'failed_on' => [], 'loaded' => false];
            $hosts[$host]['failed_on'] = array_values(array_unique([...$hosts[$host]['failed_on'], ...$failedOn]));
            $hosts[$host]['loaded'] = $hosts[$host]['loaded'] || $loaded;
        };

        foreach ($targets as $target) {
            $add($target['hostname'], 'requested');
            if ($apex = $this->suffixes->registrableDomain($target['hostname'])) {
                $apexes[$apex] = true;
                $add($apex, 'apex');
                $add("www.{$apex}", 'apex');
            }
        }

        try {
            foreach ($this->checker->checkMany($targets, false)['checks'] as $check) {
                foreach ($this->checker->relatedHosts($check['results']) as $related) {
                    $add($related['host'], 'page_load', $related['failed_on'], true);
                }
            }
        } catch (Throwable $exception) {
            report($exception);
            $notes[] = 'The network probes could not load the site, so only certificate and domain-derived hosts are listed.';
        }

        if ($includeCertificates) {
            foreach (array_keys($apexes) as $apex) {
                $found = $this->certificateHosts($apex);
                if ($found === null) {
                    $notes[] = "Certificate-transparency lookup for {$apex} did not respond; try again shortly.";

                    continue;
                }
                foreach ($found as $host) {
                    $add($host, 'certificate');
                }
            }
        }

        $result = [];
        foreach ($hosts as $host) {
            $apex = $this->suffixes->registrableDomain($host['host']);
            $result[] = $host + [
                'apex' => $apex,
                'same_site' => $apex !== null && isset($apexes[$apex]),
                'listed' => $this->engine->locate($host['host'])->map(fn ($entry) => ['list' => $entry->policyList->name.($entry->domain !== $host['host'] ? " (via {$entry->domain})" : ''), 'kind' => $entry->policyList->kind->value])->all(),
            ];
        }

        usort($result, fn (array $a, array $b) => [! $a['same_site'], $a['apex'], $a['host']] <=> [! $b['same_site'], $b['apex'], $b['host']]);

        return [
            'hosts' => $result,
            'apexes' => array_keys($apexes),
            'notes' => $notes,
            'duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
        ];
    }

    /**
     * Subdomains of a registrable domain seen in issued certificates (crt.sh).
     *
     * @return list<string>|null null when the lookup failed
     */
    public function certificateHosts(string $apex): ?array
    {
        try {
            $response = Http::acceptJson()->connectTimeout(4)->timeout(20)->retry(1, 500, throw: false)
                ->get('https://crt.sh/', ['q' => '%.'.$apex, 'output' => 'json', 'exclude' => 'expired']);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful() || ! is_array($response->json())) {
            return null;
        }

        $names = [];
        foreach ($response->json() as $certificate) {
            foreach (preg_split('/\s+/', (string) ($certificate['name_value'] ?? '')) ?: [] as $name) {
                $name = strtolower(ltrim(trim($name), '*.'));
                if ($name === $apex || str_ends_with($name, '.'.$apex)) {
                    $names[$name] = true;
                }
            }
        }

        $names = array_keys($names);
        sort($names);

        return array_slice($names, 0, self::CERTIFICATE_LIMIT);
    }
}
