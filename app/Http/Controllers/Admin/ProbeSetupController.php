<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NetworkAssignment;
use App\Models\NetworkProfile;
use App\Services\AuditLogger;
use App\Services\Network\ManagesReservations;
use App\Services\Network\NetworkProviderManager;
use App\Services\Network\ProviderRequestFailed;
use App\Services\Policy\ProbePlanner;
use App\Services\SettingsRepository;
use App\Services\Website\ProbeDiagnostics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * One-click fixes from a probe's setup checklist: reserve its address for
 * its MAC in UniFi, move it to an address outside the DHCP pool, or mark a
 * warning as reviewed.
 */
class ProbeSetupController extends Controller
{
    public function reserve(Request $request, NetworkProfile $profile, NetworkProviderManager $providers, ProbeDiagnostics $diagnostics, AuditLogger $audit): RedirectResponse
    {
        $check = $this->dhcpCheck($profile, $diagnostics);
        $provider = rescue(fn () => $providers->current(), null, false);
        if (! $provider instanceof ManagesReservations || $providers->integration()?->mode !== 'active') {
            return back()->with('error', 'Reserving needs the network provider in Active mode (Settings → Network provider). Or add the reservation in UniFi by hand: '.($check['mac'] ?? 'the probe MAC').' → '.($check['ip'] ?? 'its address').'.');
        }
        if (! $check || ! $check['mac'] || ! $check['ip'] || ! $check['network_id']) {
            return back()->with('error', 'Run the checklist first: the probe has to be running on its network.');
        }

        try {
            $provider->reserveAddress($check['mac'], 'Filterdesk probe: '.$profile->name, $check['network_id'], $check['ip']);
        } catch (ProviderRequestFailed $exception) {
            return back()->with('error', $exception->getMessage());
        }
        Cache::forget('probe.reservations');
        $diagnostics->for($profile, refresh: true);
        $audit->log('probe.address_reserved', $profile, ['mac' => $check['mac'], 'ip' => $check['ip']]);

        return back()->with('success', "Reserved {$check['ip']} for {$profile->name} ({$check['mac']}) in UniFi.");
    }

    public function useOutsidePool(Request $request, NetworkProfile $profile, ProbePlanner $planner, AuditLogger $audit): RedirectResponse
    {
        $network = NetworkAssignment::query()->where('provider_network_id', (string) $profile->unifi_network_id)->first();
        $address = $network ? $planner->addressOutsidePool($network) : null;
        if (! $address) {
            return back()->with('error', 'No free address outside the DHCP pool is known for this network. Shrink the pool in UniFi, or reserve the current address instead.');
        }

        $saved = $planner->saved();
        $planner->save(['addresses' => [$network->provider_network_id => $address] + $saved['addresses']] + $saved);
        $audit->log('probe.address_changed', $profile, ['address' => $address]);
        $plan = $planner->plan()->first(fn (array $plan) => $plan['network']->provider_network_id === $network->provider_network_id);

        return back()->with('success', "{$profile->name} will use {$address}. Apply it on the Docker host: regenerate compose.vlan.yml (Guided setup, or docker compose exec -T app php artisan probes:compose > compose.vlan.yml), then docker network rm ".($plan['docker_network'] ?? 'its network').', run its create command from the top of the file, and docker compose up -d.');
    }

    public function review(Request $request, NetworkProfile $profile, ProbeDiagnostics $diagnostics, SettingsRepository $settings, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate(['check' => ['required', 'string', 'max:40', 'regex:/^[a-z_]+$/'], 'reviewed' => ['required', 'boolean']]);
        $check = collect($diagnostics->for($profile)['checks'])->firstWhere('key', $validated['check']);
        if (! $check || $validated['check'] === 'enabled') {
            return back()->with('error', 'That step cannot be marked as reviewed.');
        }

        $reviewed = (array) (((array) $settings->get('probes.reviewed', []))[$profile->id] ?? []);
        if ($request->boolean('reviewed')) {
            if ($check['status'] !== 'warn') {
                return back()->with('error', 'Only warnings can be marked as reviewed; a failing step has to be fixed.');
            }
            $reviewed[$validated['check']] = md5($check['detail']);
        } else {
            unset($reviewed[$validated['check']]);
        }
        $all = (array) $settings->get('probes.reviewed', []);
        $all[$profile->id] = $reviewed;
        $settings->set('probes.reviewed', $all);
        $audit->log('probe.check_reviewed', $profile, ['check' => $validated['check'], 'reviewed' => $request->boolean('reviewed')]);

        return back()->with('success', $request->boolean('reviewed') ? "{$check['label']} marked as reviewed for {$profile->name}. It will show again if the situation changes." : "{$check['label']} counts again for {$profile->name}.");
    }

    /** @return array<string, mixed>|null */
    private function dhcpCheck(NetworkProfile $profile, ProbeDiagnostics $diagnostics): ?array
    {
        return collect($diagnostics->for($profile)['checks'])->firstWhere('key', 'dhcp');
    }
}
