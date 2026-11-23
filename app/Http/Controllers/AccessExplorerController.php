<?php

namespace App\Http\Controllers;

use App\Models\NetworkType;
use App\Services\Policy\AccessExplorer;
use App\Services\Policy\ControllerSync;
use App\Services\Policy\PolicyListParser;
use App\Services\Policy\WhatIf;
use App\Services\SettingsRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * "Who can reach what, and why": the full trace for IT, a what-if for
 * category changes, and a plain answer for anyone ("Why is this blocked?").
 */
class AccessExplorerController extends Controller
{
    public function show(Request $request, AccessExplorer $explorer, WhatIf $whatIf, SettingsRepository $settings, PolicyListParser $parser): View
    {
        $validated = $request->validate([
            'site' => ['nullable', 'string', 'max:253'],
            'type' => ['nullable', 'string', 'max:40'],
            'at' => ['nullable', 'date'],
            'category' => ['nullable', 'string', 'max:60'],
            'change' => ['nullable', 'in:block,unblock'],
            'whatif_type' => ['nullable', 'string', 'max:40'],
        ]);
        $types = $explorer->types();
        $site = $this->host($validated['site'] ?? null, $parser);
        $at = filled($validated['at'] ?? null) ? $settings->parseLocal($validated['at']) : null;
        $chosen = array_filter([$validated['type'] ?? null]);

        $results = $site ? $types->filter(fn (NetworkType $type) => $chosen === [] || in_array($type->key, $chosen, true))
            ->map(fn (NetworkType $type) => $explorer->explain($site, $type, $at))->values() : collect();

        $whatIfType = filled($validated['whatif_type'] ?? null) ? NetworkType::byKey($validated['whatif_type']) : null;

        return view('admin.policy.explorer', [
            'types' => $types, 'site' => $site, 'type' => $validated['type'] ?? '', 'at' => $at, 'results' => $results,
            'categories' => $this->categories(),
            'whatIf' => $whatIfType && filled($validated['category'] ?? null) ? $whatIf->category($whatIfType, $validated['category'], ($validated['change'] ?? 'block') === 'block') : null,
            'whatIfInput' => $validated,
        ]);
    }

    /** A plain answer for anyone, for the networks they can ask about. */
    public function why(Request $request, AccessExplorer $explorer, PolicyListParser $parser): View
    {
        $validated = $request->validate(['site' => ['nullable', 'string', 'max:2000']]);
        $site = $this->host($validated['site'] ?? null, $parser);
        $user = $request->user();
        $types = $user->role === 'student'
            ? NetworkType::query()->where('key', 'student')->get()
            : NetworkType::query()->where('requestable', true)->ordered()->get();

        $answers = $site ? $types->map(fn (NetworkType $type) => ['type' => $type] + $explorer->plain($explorer->explain($site, $type)))->values() : collect();

        return view('why', ['site' => $site, 'answers' => $answers]);
    }

    private function host(?string $input, PolicyListParser $parser): ?string
    {
        if (blank($input)) {
            return null;
        }
        $parsed = $parser->parse((string) $input, 'domains');

        return $parsed[0] ?? null;
    }

    /** @return list<string> UniFi content-filter category ids */
    private function categories(): array
    {
        return Cache::remember('unifi.categories.available', 3600, fn () => rescue(fn () => app(ControllerSync::class)->reader()?->availableCategories() ?? [], [], false)) ?: [];
    }
}
