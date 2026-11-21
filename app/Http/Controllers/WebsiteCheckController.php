<?php

namespace App\Http\Controllers;

use App\Models\WebsiteCheck;
use App\Services\Domains\DomainCategorizer;
use App\Services\Domains\DomainNormalizer;
use App\Services\Website\WebsiteChecker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WebsiteCheckController extends Controller
{
    public function create(): View
    {
        return view('checks.create');
    }

    public function store(Request $request, DomainNormalizer $domains, WebsiteChecker $checker): View
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

        $result = $checker->checkMany($targets, true);

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

        return view('checks.create', [
            'checks' => $result['checks'], 'duration' => $result['duration_ms'],
            'categories' => rescue(fn () => app(DomainCategorizer::class)->many(collect($result['checks'])->pluck('hostname')->all()), [], false),
        ]);
    }
}
