<?php

namespace App\Http\Controllers;

use App\Models\FilterPolicy;
use App\Models\NetworkAssignment;
use App\Models\NetworkProfile;
use App\Models\PolicyEntry;
use App\Models\WebsiteCheck;
use App\Services\Domains\DomainCategorizer;
use App\Services\Domains\DomainNormalizer;
use App\Services\Policy\CategoryCoverage;
use App\Services\Policy\PolicyEngine;
use App\Services\Website\NetworkLocator;
use App\Services\Website\ProbeSettings;
use App\Services\Website\WebsiteChecker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

class WebsiteCheckController extends Controller
{
    public function create(Request $request, NetworkLocator $locator): View
    {
        return view('checks.create', ['here' => $locator->locate($request->ip())]);
    }

    /**
     * Results that are blocked only by a network's school-hours filter, with
     * its schedule: not in the always-on filter's list or categories, but in
     * the school-hours filter's.
     *
     * @param  list<array<string, mixed>>  $checks
     * @return array<string, array<string, string>> hostname => profile key => schedule
     */
    private function schoolHoursOnly(array $checks): array
    {
        $engine = app(PolicyEngine::class);
        $coverage = app(CategoryCoverage::class)->forDomains(array_column($checks, 'hostname'));
        $profiles = NetworkProfile::query()->get()->keyBy('key');
        $result = [];
        foreach ($checks as $check) {
            $host = $check['hostname'];
            foreach ($check['results'] as $key => $outcome) {
                if (($outcome['status'] ?? null) !== 'blocked' || ! ($profile = $profiles->get($key))?->unifi_network_id) {
                    continue;
                }
                $policy = NetworkAssignment::query()->where('provider_network_id', $profile->unifi_network_id)->first()?->filterPolicy;
                $layer = $policy?->activeHoursLayer();
                if (! $layer) {
                    continue;
                }
                $byCategory = fn (FilterPolicy $filter) => collect($coverage[$host] ?? [])->contains(fn (array $hit) => $hit['policy']->is($filter));
                $byParent = $engine->coveredBy(array_flip($policy->current_blocklist ?? []), $host) || $byCategory($policy);
                $byLayer = $engine->coveredBy(array_flip($layer->current_blocklist ?? []), $host) || $byCategory($layer);
                if ($byLayer && ! $byParent) {
                    $result[$host][$key] = (string) ($layer->sync_details['schedule'] ?? 'school hours');
                }
            }
        }

        return $result;
    }

    public function store(Request $request, DomainNormalizer $domains, WebsiteChecker $checker, NetworkLocator $locator): View
    {
        $validated = $request->validate(['websites' => ['required', 'string', 'max:10000']]);
        $values = array_values(array_unique(array_filter(array_map(
            'trim',
            preg_split('/[\r\n,]+/', $validated['websites']) ?: []
        ))));

        if ($values === [] || count($values) > 10) {
            throw ValidationException::withMessages(['websites' => 'Enter between 1 and 10 website URLs, one per line.']);
        }

        $targets = [];
        foreach ($values as $value) {
            $url = str_contains($value, '://') ? $value : "https://{$value}";
            $parts = parse_url($url);
            if (! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
                throw ValidationException::withMessages(['websites' => "{$value} is not an HTTP or HTTPS website URL."]);
            }

            $targets[] = ['url' => $url, 'hostname' => $domains->one($url)];
        }

        $here = $locator->locate($request->ip());
        try {
            // Students see only their own level of access; staff and IT see every network, theirs first.
            // Page previews (screenshot and title) only for those allowed to see them: a student checking an
            // inappropriate site gets the answer without the page.
            $previews = app(ProbeSettings::class)->previewsFor($request->user());
            $result = $checker->checkMany($targets, $previews, $request->user()->checkAudiences(), $here['profile']?->key ?? null);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['websites' => $exception->getMessage()]);
        }

        DB::transaction(function () use ($request, $result): void {
            foreach ($result['checks'] as $check) {
                $storedResults = collect($check['results'])->map(fn (array $profile) => collect($profile)->except('screenshot')->all())->all();
                WebsiteCheck::query()->create([
                    'user_id' => $request->user()->id,
                    'url' => $check['url'],
                    'hostname' => $check['hostname'],
                    'results' => $storedResults,
                    'duration_ms' => $check['duration_ms'],
                ]);
            }
        });
        PolicyEntry::markSeen(array_column($result['checks'], 'hostname'));
        if (! $previews) {
            $result['checks'] = array_map(fn (array $check) => ['results' => array_map(fn (array $profile) => collect($profile)->except(['screenshot', 'page_title'])->all(), $check['results'])] + $check, $result['checks']);
        }

        return view('checks.create', [
            'here' => $here, 'previews' => $previews,
            'checks' => $result['checks'],
            'schoolHoursOnly' => rescue(fn () => $this->schoolHoursOnly($result['checks']), [], false), 'duration' => $result['duration_ms'],
            'categories' => rescue(fn () => app(DomainCategorizer::class)->many(collect($result['checks'])->pluck('hostname')->all()), [], false),
        ]);
    }
}
