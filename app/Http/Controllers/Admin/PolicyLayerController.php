<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FilterPolicy;
use App\Services\AuditLogger;
use App\Services\Network\ProviderRequestFailed;
use App\Services\Policy\ControllerPusher;
use App\Services\Policy\ControllerSync;
use App\Services\Policy\PolicyEngine;
use App\Services\Policy\SyncChecker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Creates a layer: a second controller filter on the same networks as a
 * policy, carrying that policy's blocklist feeds. Controllers combine every
 * filter on a network and a block in any of them wins, so the app keeps
 * allowed hostnames out of the layer before it pushes.
 */
class PolicyLayerController extends Controller
{
    /** Used when the parent filter blocks no categories; UniFi will not save a filter without one. */
    public const FALLBACK_CATEGORY = 'MALWARE';

    public function store(Request $request, FilterPolicy $policy, ControllerPusher $pusher, ControllerSync $sync, SyncChecker $checker, PolicyEngine $engine, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'push' => ['nullable', 'boolean'],
        ]);
        if ($policy->isLayer() || $policy->layer()->exists()) {
            return back()->with('error', $policy->isLayer() ? 'A layer cannot have its own layer.' : "{$policy->name} already has a layer.");
        }
        $writer = $pusher->writer();
        if (! $writer || ! $pusher->enabled() || blank($policy->provider_reference)) {
            return back()->with('error', 'Link this policy to a controller filter and set the network provider to Active first.');
        }

        $sync->forget();
        $parentFilter = $sync->filterFor($policy, $sync->state(true));
        if (! $parentFilter) {
            return back()->with('error', 'The linked controller filter could not be read.');
        }
        if ($parentFilter['network_ids'] === []) {
            return back()->with('error', "{$parentFilter['name']} is not on any network in the controller, so a layer would filter nothing.");
        }

        $categories = $parentFilter['categories'] ?: [self::FALLBACK_CATEGORY];
        try {
            $filter = $writer->createContentFilter($validated['name'], $parentFilter['network_ids'], $categories);
        } catch (ProviderRequestFailed $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $layer = FilterPolicy::query()->create([
            'name' => $validated['name'], 'slug' => $engine->uniqueSlug($validated['name']), 'audience' => $policy->audience,
            'layer_of_id' => $policy->id, 'provider_reference' => $filter['id'], 'provider_name' => $filter['name'],
            'description' => "Carries the blocklist feeds for {$policy->name}.", 'enabled' => true, 'sort_order' => $policy->sort_order,
            'current_allowlist' => [], 'current_blocklist' => [], 'snapshot_at' => now(),
        ]);
        $checker->setBaseline($layer, [], [], $filter['categories']);
        $sync->forget();
        $audit->log('policy.layer_created', $layer, ['parent' => $policy->name, 'filter' => $filter['name'], 'networks' => count($filter['network_ids']), 'categories' => $categories]);

        $message = "Created {$filter['name']} in the controller on the same ".count($filter['network_ids']).' network(s) as '.$parentFilter['name'].'.';
        if ($parentFilter['categories'] === []) {
            $message .= ' UniFi needs at least one category, so it blocks '.self::FALLBACK_CATEGORY.'.';
        }

        if (! $request->boolean('push')) {
            $checker->checkOne($layer->refresh());

            return redirect()->route('admin.policy.policies.show', $layer)->with('success', $message.' Push both filters to move the feeds across.');
        }

        // Layer first, so the feeds are blocked in the layer before they leave the parent.
        $results = collect([$layer->refresh(), $policy->refresh()])->map(fn (FilterPolicy $target) => $pusher->push($target, $request->user()));
        $failed = $results->first(fn (array $result) => ! $result['ok']);

        return redirect()->route('admin.policy.policies.show', $layer)->with($failed ? 'error' : 'success', $message.' '.$results->pluck('message')->join(' '));
    }
}
