<?php

namespace App\Services\Domains;

use App\Models\FilterPolicy;
use App\Models\IntegrationConfig;
use App\Services\Policy\ControllerSync;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * A guide to what a website is: its content categories from an external
 * categorisation service (Cloudflare), matched to UniFi's content-filter
 * categories where the names line up, with whether each linked policy blocks
 * that category. Off until an admin enables it, because it sends the looked-up
 * domains to the service. Results are cached for a week per domain.
 */
class DomainCategorizer
{
    public const PROVIDER = 'categories';

    /** Cloudflare category names that differ from UniFi's category ids. */
    private const SYNONYMS = [
        'Adult Themes' => 'ADULT', 'Pornography' => 'PORNOGRAPHY', 'Nudity' => 'NUDITY', 'Gambling' => 'GAMBLING',
        'Social Networks' => 'SOCIAL_NETWORKS', 'Video Streaming' => 'STREAMING_MEDIA', 'Audio Streaming' => 'STREAMING_MEDIA',
        'Gaming' => 'GAMES', 'Games' => 'GAMES', 'Online Games' => 'GAMES', 'Malware' => 'MALWARE', 'Phishing' => 'PHISHING',
        'Spyware' => 'SPYWARE', 'Spam' => 'SPAM', 'Advertisements' => 'ADVERTISEMENT', 'Drugs' => 'DRUGS', 'Violence' => 'VIOLENCE',
        'Weapons' => 'WEAPONS', 'Hate & Intolerance' => 'HATE_SPEECH', 'Dating & Relationships' => 'DATING', 'Chat' => 'CHAT',
        'Instant Messengers' => 'INSTANT_MESSAGING', 'Proxy & Filter Avoidance' => 'PROXY_AND_FILTER_AVOIDANCE', 'Shopping' => 'SHOPPING',
        'Peer-to-Peer' => 'PEER_TO_PEER', 'Cryptomining' => 'CRYPTOMINING', 'Alcohol' => 'ALCOHOL', 'Tobacco' => 'TOBACCO',
        'Swimsuits & Underwear' => 'LINGERIE_AND_BIKINI', 'Lingerie & Bikini' => 'LINGERIE_AND_BIKINI', 'Child Abuse' => 'CHILD_ABUSE',
    ];

    public function integration(): ?IntegrationConfig
    {
        return IntegrationConfig::query()->where('provider', self::PROVIDER)->first();
    }

    public function enabled(): bool
    {
        $config = $this->integration();

        return $config !== null && $config->enabled && filled($config->credentials['token'] ?? null)
            && (($config->configuration['source'] ?? 'radar') !== 'intel' || filled($config->configuration['account_id'] ?? null));
    }

    /**
     * Categories for several domains, keyed by domain.
     *
     * @param  list<string>  $domains
     * @return array<string, array{ok: bool, categories: list<string>, risks: list<string>, unifi: list<array{id: string, label: string, blocked_by: list<string>}>, message?: string}>
     */
    public function many(array $domains): array
    {
        if (! $this->enabled()) {
            return [];
        }

        return collect($domains)->unique()->take(25)->mapWithKeys(fn (string $domain) => [$domain => $this->lookup($domain)])->all();
    }

    /** @return array{ok: bool, categories: list<string>, risks: list<string>, unifi: list<array{id: string, label: string, blocked_by: list<string>}>, message?: string} */
    public function lookup(string $domain, bool $refresh = false): array
    {
        $domain = strtolower(trim($domain));
        $key = 'domain.categories.'.md5($domain);
        if ($refresh) {
            Cache::forget($key);
        }

        $raw = Cache::get($key);
        if ($raw === null) {
            $raw = $this->fetch($domain);
            // Cache answers for a week; failures only briefly, so a fixed token takes effect quickly.
            Cache::put($key, $raw, $raw['ok'] ? now()->addWeek() : now()->addMinutes(5));
        }

        return $raw + ['unifi' => $raw['ok'] ? $this->matchUnifi($raw['categories']) : []];
    }

    /** @return array{ok: bool, categories: list<string>, risks: list<string>, message?: string} */
    private function fetch(string $domain): array
    {
        $config = $this->integration();
        $token = (string) ($config?->credentials['token'] ?? '');
        $intel = ($config?->configuration['source'] ?? 'radar') === 'intel';

        try {
            $request = Http::withToken($token)->acceptJson()->connectTimeout(3)->timeout(6);
            $response = $intel
                ? $request->get('https://api.cloudflare.com/client/v4/accounts/'.rawurlencode((string) $config->configuration['account_id']).'/intel/domain', ['domain' => $domain])
                : $request->get('https://api.cloudflare.com/client/v4/radar/ranking/domain/'.rawurlencode($domain));
        } catch (Throwable) {
            return ['ok' => false, 'categories' => [], 'risks' => [], 'message' => 'The category service could not be reached.'];
        }

        if (in_array($response->status(), [401, 403], true)) {
            return ['ok' => false, 'categories' => [], 'risks' => [], 'message' => 'The category service refused the API token. Check it under Settings → Website categories.'];
        }
        if ($response->status() === 404) {
            return ['ok' => true, 'categories' => [], 'risks' => [], 'message' => 'Not categorised yet.'];
        }
        if (! $response->successful()) {
            return ['ok' => false, 'categories' => [], 'risks' => [], 'message' => 'The category service answered with HTTP '.$response->status().'.'];
        }

        $result = (array) $response->json('result', []);
        $names = fn (array $items) => collect($items)->pluck('name')->filter(fn ($name) => is_string($name) && $name !== '')->unique()->values()->all();
        $categories = $intel
            ? $names([...(array) ($result['content_categories'] ?? []), ...(array) ($result['inherited_content_categories'] ?? [])])
            : $names((array) ($result['details_0']['categories'] ?? []));
        $risks = $intel ? $names([...(array) ($result['risk_types'] ?? []), ...(array) ($result['inherited_risk_types'] ?? [])]) : [];

        return ['ok' => true, 'categories' => $categories, 'risks' => $risks] + ($categories === [] && $risks === [] ? ['message' => 'Not categorised yet.'] : []);
    }

    /**
     * Match service category names to UniFi content-filter category ids, and
     * say which linked policies already block each one.
     *
     * @param  list<string>  $names
     * @return list<array{id: string, label: string, blocked_by: list<string>}>
     */
    private function matchUnifi(array $names): array
    {
        $state = rescue(fn () => app(ControllerSync::class)->state(), ['filters' => [], 'error' => true], false);
        $available = Cache::remember('unifi.categories.available', 3600, fn () => rescue(fn () => app(ControllerSync::class)->reader()?->availableCategories() ?? [], [], false));
        $filters = collect($state['filters'] ?? []);
        $policies = FilterPolicy::query()->main()->whereNotNull('provider_reference')->get(['name', 'provider_reference']);

        return collect($names)->map(function (string $name) use ($available) {
            $id = self::SYNONYMS[$name] ?? Str::upper(preg_replace('/[^A-Za-z0-9]+/', '_', trim($name)));

            return in_array($id, $available, true) || isset(self::SYNONYMS[$name]) ? $id : null;
        })->filter()->unique()->values()->map(fn (string $id) => [
            'id' => $id,
            'label' => Str::of($id)->replace('_', ' ')->lower()->ucfirst()->toString(),
            'blocked_by' => $policies->filter(fn ($policy) => in_array($id, (array) ($filters->firstWhere('id', $policy->provider_reference)['categories'] ?? []), true))->pluck('name')->values()->all(),
        ])->all();
    }
}
