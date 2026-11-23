<?php

namespace App\Http\Controllers;

use App\Models\PolicyEntry;
use App\Models\WebsiteCheck;
use App\Services\Domains\DomainCategorizer;
use App\Services\Domains\DomainNormalizer;
use App\Services\Website\NetworkLocator;
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
            $result = $checker->checkMany($targets, true, $request->user()->checkAudiences(), $here['profile']?->key ?? null);
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

        return view('checks.create', [
            'here' => $here,
            'checks' => $result['checks'], 'duration' => $result['duration_ms'],
            'categories' => rescue(fn () => app(DomainCategorizer::class)->many(collect($result['checks'])->pluck('hostname')->all()), [], false),
        ]);
    }
}
