<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RunIpListSizeTest;
use App\Models\FirewallList;
use App\Models\IntegrationConfig;
use App\Services\AuditLogger;
use App\Services\Network\FirewallRuleMode;
use App\Services\Network\IpListSizeTester;
use App\Services\Network\NetworkProviderManager;
use App\Services\Policy\FirewallListPusher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * Settings → Network provider → "Test larger UniFi IP lists": an admin
 * starts the test in the browser, it runs in the background, and the
 * results can set Addresses per UniFi IP list in one click.
 */
class IpListSizeTestController extends Controller
{
    public function start(Request $request, FirewallListPusher $pusher): RedirectResponse
    {
        $validated = $request->validate([
            'sizes' => ['required', 'array', 'min:1', 'max:4'], 'sizes.*' => ['integer', Rule::in(IpListSizeTester::SIZES)],
            'sequential' => ['nullable', 'boolean'],
            'understood' => ['accepted'],
        ], ['understood.accepted' => 'Tick the box to confirm you are watching the gateway.', 'sizes.required' => 'Choose at least one size.']);
        if (! $pusher->canWrite()) {
            return back()->with('error', 'Set the network provider to Active first.');
        }
        if ((Cache::get(IpListSizeTester::STATUS_KEY)['state'] ?? null) === 'running') {
            return back()->with('error', 'A test is already running.');
        }
        Cache::put(IpListSizeTester::STATUS_KEY, ['state' => 'running', 'since' => now()->toIso8601String(), 'step' => 'Starting'], 1800);
        RunIpListSizeTest::dispatch(array_map('intval', $validated['sizes']), $request->boolean('sequential'), $request->user()->id);

        return back()->with('success', 'The test is running in the background. Watch the gateway in UniFi meanwhile; refresh this page for the results.');
    }

    /** Set Addresses per UniFi IP list from the last test. */
    public function use(Request $request, IpListSizeTester $tester, NetworkProviderManager $providers, AuditLogger $audit): RedirectResponse
    {
        $size = $tester->suggested();
        $integration = $providers->integration();
        if (! $size || ! $integration instanceof IntegrationConfig) {
            return back()->with('error', 'The last test has no size to suggest. Run it again with real data.');
        }
        $before = $integration->configuration['max_ip_group_entries'] ?? FirewallListPusher::GROUP_LIMIT;
        $integration->forceFill(['configuration' => ['max_ip_group_entries' => $size] + (array) $integration->configuration])->save();
        $audit->log('settings.ip_list_size_changed', $integration, ['from' => $before, 'to' => $size, 'from_test' => true]);
        FirewallList::query()->update(['max_part_entries' => null]);

        return back()->with('success', 'Addresses per UniFi IP list is now '.number_format($size).'. Lists are split at that size from their next push.');
    }

    /** The firewall list options, from the Protection page: addresses per UniFi IP list and who manages the rules. */
    public function update(Request $request, NetworkProviderManager $providers, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'max_ip_group_entries' => ['required', 'integer', 'min:1000', 'max:'.FirewallListPusher::MAX_GROUP_LIMIT],
            'firewall_rules' => ['required', Rule::in([FirewallRuleMode::MANAGE, FirewallRuleMode::LISTS_ONLY])],
        ]);
        $integration = $providers->integration();
        if (! $integration instanceof IntegrationConfig) {
            return back()->with('error', 'Set up the network provider first (Settings → Network provider).');
        }
        $configuration = (array) $integration->configuration;
        $size = ['from' => $configuration['max_ip_group_entries'] ?? FirewallListPusher::GROUP_LIMIT, 'to' => (int) $validated['max_ip_group_entries']];
        $rules = ['from' => FirewallRuleMode::current($integration), 'to' => $validated['firewall_rules']];
        $integration->forceFill(['configuration' => ['max_ip_group_entries' => $size['to'], 'firewall_rules' => $rules['to']] + $configuration])->save();
        if ($size['from'] !== $size['to']) {
            $audit->log('settings.ip_list_size_changed', $integration, $size);
            // Try the new size again; a list UniFi still refuses is split smaller on its next push.
            FirewallList::query()->update(['max_part_entries' => null]);
        }
        if ($rules['from'] !== $rules['to']) {
            $audit->log('settings.firewall_rules_changed', $integration, $rules);
        }

        return back()->with('success', 'Firewall list options saved. Lists use them from their next push.');
    }
}
