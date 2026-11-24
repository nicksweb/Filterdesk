<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PolicyList;
use App\Services\Domains\DomainNormalizer;
use App\Services\Policy\DomainScanner;
use App\Services\Policy\PolicyEngine;
use App\Services\Policy\ProtectedSites;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/** Scan a site for every hostname it uses, then allow or block them in one step. */
class DomainScanController extends Controller
{
    public function show(PolicyEngine $engine): View
    {
        return view('admin.policy.scan', ['lists' => PolicyList::query()->ordered()->with('feed:id,policy_list_id')->get(), 'scan' => null, 'rules' => $engine->rules(), 'protectedSites' => app(ProtectedSites::class)]);
    }

    public function store(Request $request, DomainNormalizer $normalizer, DomainScanner $scanner, PolicyEngine $engine): View
    {
        $validated = $request->validate([
            'websites' => ['required', 'string', 'max:2000'],
            'certificates' => ['nullable', 'boolean'],
        ]);

        $values = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $validated['websites']) ?: []))));
        if ($values === [] || count($values) > 5) {
            throw ValidationException::withMessages(['websites' => 'Enter between 1 and 5 websites, one per line.']);
        }

        $targets = [];
        foreach ($values as $value) {
            $url = str_contains($value, '://') ? $value : "https://{$value}";
            $parts = parse_url($url);
            if (! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
                throw ValidationException::withMessages(['websites' => "{$value} is not an HTTP or HTTPS website."]);
            }
            try {
                $targets[] = ['url' => $url, 'hostname' => $normalizer->one($url)];
            } catch (Throwable) {
                throw ValidationException::withMessages(['websites' => "{$value} is not a valid public hostname."]);
            }
        }

        return view('admin.policy.scan', [
            'lists' => PolicyList::query()->ordered()->with('feed:id,policy_list_id')->get(),
            'scan' => $scanner->scan($targets, $request->boolean('certificates')),
            'websites' => implode("\n", $values),
            'certificates' => $request->boolean('certificates'),
            'rules' => $engine->rules(),
            'protectedSites' => app(ProtectedSites::class),
        ]);
    }
}
