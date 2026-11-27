<?php

namespace App\Http\Controllers;

use App\Models\NetworkProfile;
use App\Models\NetworkType;
use App\Services\AuditLogger;
use App\Services\Domains\DomainNormalizer;
use App\Services\Website\ProbeSettings;
use App\Services\Website\WebsiteChecker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/**
 * "Is this site blocked?" without signing in, when Settings → Network
 * profiles → Website checks allows it, also as a bare page for an iframe
 * on the school intranet or LMS. Up to 3 websites, one plain answer per
 * network type, no addresses, previews, categories or network names.
 * Results are shared for 10 minutes; every check is in the audit log.
 */
class PublicCheckController extends Controller
{
    public const MAX_SITES = 3;

    public function show(Request $request, ProbeSettings $settings): View
    {
        abort_unless($settings->publicChecks(), 404);

        return view('checks.public', ['embed' => $request->routeIs('checks.embed'), 'results' => null, 'websites' => (string) $request->query('websites', '')]);
    }

    public function store(Request $request, ProbeSettings $settings, DomainNormalizer $normalizer, WebsiteChecker $checker, AuditLogger $audit): View
    {
        abort_unless($settings->publicChecks(), 404);
        $validated = $request->validate(['websites' => ['required', 'string', 'max:1000']]);
        $values = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,]+/', $validated['websites']) ?: []))));
        if ($values === [] || count($values) > self::MAX_SITES) {
            throw ValidationException::withMessages(['websites' => 'Enter up to '.self::MAX_SITES.' websites.']);
        }
        $targets = [];
        foreach ($values as $value) {
            try {
                $host = $normalizer->one($value);
            } catch (Throwable) {
                throw ValidationException::withMessages(['websites' => "{$value} isn't a website address."]);
            }
            $targets[$host] = ['url' => "https://{$host}", 'hostname' => $host];
        }

        $types = NetworkProfile::query()->where('enabled', true)->pluck('audience', 'key');
        $results = [];
        foreach ($targets as $host => $target) {
            try {
                // DNS-only would do for most; the same answer for everyone for 10 minutes keeps the probes free.
                $check = Cache::remember('public-check:'.sha1($host), 600, fn () => $checker->checkMany([$target], false)['checks'][0] ?? null);
            } catch (RuntimeException) {
                $check = null;
            }
            $results[$host] = collect($check['results'] ?? [])->groupBy(fn (array $result, string $key) => NetworkType::labelFor($types[$key] ?? null), true)
                ->map(fn ($group) => match (true) {
                    $group->contains(fn (array $result) => in_array($result['status'] ?? '', ['blocked', 'dns_failed', 'unreachable'], true)) => 'blocked',
                    $group->every(fn (array $result) => ($result['status'] ?? '') === 'allowed') => 'allowed',
                    default => 'unknown',
                })->all();
        }
        $audit->log('check.public', null, ['ip' => $request->ip(), 'hosts' => array_keys($targets), 'embed' => $request->routeIs('checks.embed.store')]);

        return view('checks.public', ['embed' => $request->routeIs('checks.embed.store'), 'results' => $results, 'websites' => implode("\n", $values)]);
    }
}
