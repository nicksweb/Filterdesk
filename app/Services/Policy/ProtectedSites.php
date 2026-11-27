<?php

namespace App\Services\Policy;

use App\Models\IntegrationConfig;
use App\Services\Domains\PublicSuffixList;
use App\Services\SettingsRepository;
use App\Services\Support;

/**
 * A safety net: sites that must never be blocked, whatever a list, feed,
 * scan, request or the controller says. "google.com" protects the top level
 * (google.com and www.google.com, and so anything a block of google.com
 * would cover) while a subdomain such as gemini.google.com can still be
 * blocked; "*.gstatic.com" protects the whole domain, every subdomain too.
 * A public suffix on its own (com, com.au) is always protected.
 *
 * Protected hostnames are left out of every filter's block list when the
 * app works out what to push, so a block of one is never sent to UniFi
 * (and one added in UniFi is taken out again on the next push). Adding one
 * by hand is refused with a message. Set under Settings → Protected sites.
 */
class ProtectedSites
{
    public const KEY = 'policy.protected_sites';

    /** @var list<string> sensible defaults: search, sign-in, updates and the services they need */
    public const DEFAULTS = [
        'google.com', 'bing.com', 'microsoft.com', 'apple.com', 'office.com', 'microsoft365.com', 'live.com', 'outlook.com', 'icloud.com',
        'accounts.google.com', '*.gstatic.com', '*.googleapis.com', '*.microsoftonline.com', 'login.live.com', '*.windowsupdate.com',
        '*.msftconnecttest.com', 'captive.apple.com', 'connectivitycheck.gstatic.com',
    ];

    /** @var array<string, list<string>>|null */
    private ?array $rules = null;

    public function __construct(private readonly SettingsRepository $settings, private readonly PublicSuffixList $suffixes) {}

    /**
     * Always protected, whatever the list says: the way to get help and to
     * ask for access. The helpdesk's support article site and email domain
     * (Settings → Help and support), this app's own address, and the
     * single sign-on server.
     *
     * @return array<string, string> entry => why
     */
    public function automatic(): array
    {
        $support = app(Support::class)->all();
        $host = fn (?string $url): ?string => filled($url) ? (strtolower((string) parse_url(str_contains((string) $url, '://') ? (string) $url : "https://{$url}", PHP_URL_HOST)) ?: null) : null;
        $entries = [];
        if ($article = $host($support['article_url'] ?? null)) {
            $entries["*.{$article}"] = 'the helpdesk support article (Help and support)';
        }
        if (filled($support['email'] ?? null) && str_contains($support['email'], '@')) {
            $entries[strtolower(substr(strrchr($support['email'], '@'), 1))] = 'the helpdesk email domain (Help and support)';
        }
        if ($app = $host(config('app.url'))) {
            $entries["*.{$app}"] = 'this app (people check sites and ask for access here)';
        }
        $issuer = rescue(fn () => IntegrationConfig::query()->where('provider', 'oidc')->where('enabled', true)->first()?->configuration['issuer'] ?? null, null, false);
        if ($sso = $host($issuer)) {
            $entries["*.{$sso}"] = 'single sign-on (people sign in here)';
        }

        return array_filter($entries, fn ($why, string $entry) => ! in_array(ltrim($entry, '*.'), ['localhost', ''], true) && str_contains($entry, '.'), ARRAY_FILTER_USE_BOTH);
    }

    /** @return list<string> as entered: "example.com" or "*.example.com" */
    public function all(): array
    {
        $stored = $this->settings->get(self::KEY);

        return $stored === null ? self::DEFAULTS : array_values(array_filter(array_map('strval', (array) $stored)));
    }

    /** @param list<string> $sites */
    public function save(array $sites): void
    {
        $this->settings->set(self::KEY, self::normalise($sites));
        $this->rules = null;
    }

    /**
     * Clean a pasted list: lower case, no scheme or path, one of each.
     *
     * @param  list<string>  $sites
     * @return list<string>
     */
    public static function normalise(array $sites): array
    {
        $clean = [];
        foreach ($sites as $site) {
            $site = strtolower(trim((string) $site));
            $site = preg_replace('#^[a-z]+://#', '', $site);
            $site = rtrim(explode('/', $site)[0], '.');
            $wildcard = str_starts_with($site, '*.');
            $host = $wildcard ? substr($site, 2) : $site;
            if ($host !== '' && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $host)) {
                $clean[] = ($wildcard ? '*.' : '').$host;
            }
        }

        return array_values(array_unique($clean));
    }

    /** Whether a block of this hostname would block a protected site. */
    public function protects(string $host): bool
    {
        return $this->reason($host) !== null;
    }

    /** The protected entry that covers this hostname, or null. */
    public function reason(string $host): ?string
    {
        $host = strtolower(trim(ltrim(trim($host), '*.'), '.'));
        if ($host === '') {
            return null;
        }
        if ($this->suffixes->isPublicSuffix($host)) {
            return $host.' (a public suffix)';
        }
        $rules = $this->rules();
        $bare = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        if (in_array($bare, $rules['top'], true)) {
            return $bare;
        }
        foreach ($rules['whole'] as $domain) {
            if ($host === $domain || str_ends_with($host, ".{$domain}")) {
                return "*.{$domain}";
            }
        }
        // Exact hostnames listed without "*." that are not top levels (accounts.google.com).
        if (in_array($host, $rules['exact'], true)) {
            return $host;
        }

        return null;
    }

    /**
     * Split hostnames into the ones that may be blocked and the protected ones.
     *
     * @param  list<string>  $hosts
     * @return array{allowed: list<string>, protected: array<string, string>} protected: host => the entry that protects it
     */
    public function partition(array $hosts): array
    {
        $allowed = [];
        $protected = [];
        foreach ($hosts as $host) {
            $reason = $this->reason($host);
            if ($reason === null) {
                $allowed[] = $host;
            } else {
                $protected[$host] = $reason;
            }
        }

        return ['allowed' => $allowed, 'protected' => $protected];
    }

    /** @param array<string, string> $protected */
    /** Why a protected entry is there, when it is one of the automatic ones. */
    public function why(string $entry): ?string
    {
        return $this->automatic()[$entry] ?? null;
    }

    public static function message(array $protected): string
    {
        return 'Not blocked, because '.(count($protected) === 1 ? 'it is a protected site' : 'they are protected sites').' (Settings → Protected sites): '
            .collect($protected)->map(fn (string $reason, string $host) => $host === $reason ? $host : "{$host} ({$reason})")->join(', ').'.';
    }

    /** @return array{top: list<string>, whole: list<string>, exact: list<string>} */
    private function rules(): array
    {
        if ($this->rules !== null) {
            return $this->rules;
        }
        $rules = ['top' => [], 'whole' => [], 'exact' => []];
        foreach ([...$this->all(), ...array_keys($this->automatic())] as $site) {
            if (str_starts_with($site, '*.')) {
                $rules['whole'][] = substr($site, 2);
            } else {
                $bare = str_starts_with($site, 'www.') ? substr($site, 4) : $site;
                $rules[$this->suffixes->registrableDomain($bare) === $bare ? 'top' : 'exact'][] = $bare;
            }
        }

        return $this->rules = $rules;
    }
}
