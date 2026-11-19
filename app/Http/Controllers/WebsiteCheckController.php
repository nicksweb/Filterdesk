<?php

namespace App\Http\Controllers;

use App\Models\WebsiteCheck;
use App\Services\Domains\DomainNormalizer;
use App\Services\Website\WebsiteChecker;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WebsiteCheckController extends Controller
{
    public function create(): View
    {
        return view('checks.create');
    }

    public function store(Request $request, DomainNormalizer $domains, WebsiteChecker $checker): View
    {
        $validated = $request->validate(['website' => ['required', 'string', 'max:2048'], 'screenshot' => ['nullable', 'boolean']]);
        $value = trim($validated['website']);
        $url = str_contains($value, '://') ? $value : "https://{$value}";
        $parts = parse_url($url);

        if (! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            return back()->withErrors(['website' => 'Enter an HTTP or HTTPS website without embedded credentials.'])->withInput();
        }

        $hostname = $domains->one($url);
        $result = $checker->check($url, $request->boolean('screenshot'));
        $storedResults = collect($result['results'])->map(fn (array $profile) => collect($profile)->except('screenshot')->all())->all();

        WebsiteCheck::query()->create([
            'user_id' => $request->user()->id, 'url' => $url, 'hostname' => $hostname,
            'results' => $storedResults, 'duration_ms' => $result['duration_ms'],
        ]);

        return view('checks.create', ['checkedUrl' => $url, 'results' => $result['results'], 'duration' => $result['duration_ms']]);
    }
}
