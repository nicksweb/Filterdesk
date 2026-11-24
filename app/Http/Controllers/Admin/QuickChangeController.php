<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RunManualPush;
use App\Models\NetworkType;
use App\Services\Policy\ControllerPusher;
use App\Services\Policy\PolicyEngine;
use App\Services\Policy\ProtectedSites;
use App\Services\Policy\QuickChange;
use App\Services\SettingsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Quick block or allow: hostnames, the network types (or everywhere), when,
 * then a preview of the lists, filters, conflicting entries and categories
 * before anything changes.
 */
class QuickChangeController extends Controller
{
    public function show(Request $request): View
    {
        return $this->page(['domains' => (string) $request->query('domains', ''), 'kind' => $request->query('kind') === 'allow' ? 'allow' : 'block', 'types' => [], 'when' => 'all']);
    }

    public function preview(Request $request, QuickChange $quick): View
    {
        $input = $this->validated($request);
        $plan = $quick->plan($input);
        if ($plan['domains'] === []) {
            throw ValidationException::withMessages(['domains' => $plan['protected'] ? ProtectedSites::message($plan['protected']) : 'Enter at least one hostname.']);
        }

        return $this->page($input, $plan);
    }

    public function apply(Request $request, QuickChange $quick, ControllerPusher $pusher, SettingsRepository $settings): RedirectResponse
    {
        $input = $this->validated($request);
        $extra = $request->validate([
            'remove' => ['nullable', 'array', 'max:500'], 'remove.*' => ['integer'],
            'note' => ['nullable', 'string', 'max:500'],
            'expires_at' => ['nullable', 'date'],
        ]);
        $expiresAt = $settings->parseLocal($extra['expires_at'] ?? null);
        if ($expiresAt?->isPast()) {
            throw ValidationException::withMessages(['expires_at' => 'The end must be in the future.']);
        }
        $plan = $quick->plan($input);
        if ($plan['domains'] === [] || $plan['lists']->isEmpty()) {
            return redirect()->route('admin.policy.quick.show')->with('error', 'Nothing to change: no list takes these hostnames for the chosen networks.');
        }

        $counts = $quick->apply($plan, array_map('intval', $extra['remove'] ?? []), $request->user(), $extra['note'] ?? null, $expiresAt);
        $verb = $plan['kind']->value === 'block' ? 'Blocked' : 'Allowed';
        $messages = [];
        if ($counts['added']) {
            $messages[] = "{$verb} ".implode(', ', $plan['domains']).($plan['when'] === 'school' ? ' in school hours only' : ($plan['when'] === 'outside' ? ' outside school hours only' : '')).' in '.$plan['lists']->pluck('name')->join(', ').'.';
        }
        if ($counts['proposed']) {
            $messages[] = 'Site-wide changes wait for a second IT officer under Changes.';
        }
        if ($counts['removed'] || $counts['proposed_removals']) {
            $messages[] = $counts['removed'].' conflicting entr'.($counts['removed'] === 1 ? 'y' : 'ies').' removed'.($counts['proposed_removals'] ? ", {$counts['proposed_removals']} proposed for removal" : '').'.';
        }

        $published = false;
        if ($counts['added'] + $counts['removed'] > 0 && $pusher->automatic()) {
            RunManualPush::start('release', null, $request->user());
            $messages[] = 'Publishing to UniFi in the background (automatic pushing is on).';
            $published = true;
        }

        return redirect()->route('admin.policy.quick.show')->with('success', implode(' ', $messages) ?: 'Nothing changed.')
            ->with('quick_publish', ! $published && $counts['added'] + $counts['removed'] > 0 && $pusher->enabled());
    }

    /** @return array{domains: string, kind: string, types: list<string>, when: string, include_www: bool} */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'domains' => ['required', 'string', 'max:4000'],
            'kind' => ['required', 'in:allow,block'],
            'types' => ['required', 'array', 'min:1'],
            'types.*' => ['string', Rule::in([...NetworkType::query()->pluck('key')->all(), 'site'])],
            'when' => ['nullable', 'in:all,school,outside'],
            'include_www' => ['nullable', 'boolean'],
        ], ['types.required' => 'Choose the networks it is for, or Everywhere.']);

        return ['domains' => $validated['domains'], 'kind' => $validated['kind'], 'types' => array_values($validated['types']), 'when' => $validated['when'] ?? 'all', 'include_www' => $request->boolean('include_www')];
    }

    /** @param array<string, mixed>|null $plan */
    private function page(array $input, ?array $plan = null): View
    {
        $engine = app(PolicyEngine::class);
        $types = NetworkType::query()->ordered()->get();

        return view('admin.policy.quick', [
            'input' => $input,
            'plan' => $plan,
            'types' => $types,
            'hoursLayers' => $types->mapWithKeys(fn (NetworkType $type) => [$type->key => $engine->hoursLayersFor($type->key)])->filter(fn ($layers) => $layers->isNotEmpty()),
        ]);
    }
}
