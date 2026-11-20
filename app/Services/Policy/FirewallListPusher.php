<?php

namespace App\Services\Policy;

use App\Models\FirewallList;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Network\ManagesIpGroups;
use App\Services\Network\NetworkProviderManager;
use App\Services\Network\ProviderRequestFailed;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Keeps each firewall IP list's controller IP group in step. Unlike content
 * filters, the group belongs to this app: its contents are replaced with
 * the manual entries plus enabled feeds. Writes need Active mode.
 */
class FirewallListPusher
{
    public function __construct(
        private readonly NetworkProviderManager $providers,
        private readonly AuditLogger $audit,
    ) {}

    public function manager(): ?ManagesIpGroups
    {
        try {
            $provider = $this->providers->current();
        } catch (Throwable) {
            return null;
        }

        return $provider instanceof ManagesIpGroups ? $provider : null;
    }

    public function canWrite(): bool
    {
        return $this->manager() !== null && $this->providers->integration()?->mode === 'active';
    }

    /** @return array{ok: bool, message: string} */
    public function push(FirewallList $list, ?User $actor): array
    {
        $manager = $this->manager();
        if (! $manager || ! $this->canWrite()) {
            return ['ok' => false, 'message' => 'Pushing is off. Set the network provider to Active under Settings → Network provider.'];
        }
        if (blank($list->provider_reference)) {
            return ['ok' => false, 'message' => "{$list->name} is not linked to a controller IP group yet."];
        }

        try {
            return Cache::lock('firewall-push:'.$list->provider_reference, 120)->block(30, function () use ($list, $manager, $actor): array {
                ['entries' => $entries, 'total' => $total] = $list->desiredEntries();
                try {
                    $manager->writeIpGroup($list->provider_reference, $list->provider_name ?: $list->name, $list->family, $entries);
                    $rules = $manager->firewallRulesUsing($list->provider_reference);
                } catch (ProviderRequestFailed $exception) {
                    return $this->record($list, 'failed', $exception->getMessage(), $actor);
                }

                $list->forceFill(['live_count' => count($entries), 'referenced_by' => $rules])->save();
                $message = number_format(count($entries)).' address(es) in '.($list->provider_name ?: $list->name).'.'
                    .($total > count($entries) ? ' '.number_format($total - count($entries)).' more were left out by the limit of '.number_format($list->max_entries).'.' : '')
                    .($rules === [] ? ' No firewall rule uses this group yet, so nothing is blocked until you add one.' : '');

                return $this->record($list, $total > count($entries) ? 'truncated' : 'ok', $message, $actor);
            });
        } catch (LockTimeoutException) {
            return ['ok' => false, 'message' => "Another push to {$list->name} is still running."];
        }
    }

    /** @return array{ok: bool, message: string} */
    private function record(FirewallList $list, string $status, string $message, ?User $actor): array
    {
        $list->forceFill(['last_pushed_at' => now(), 'last_push_status' => $status, 'last_push_message' => $message])->save();
        $this->audit->log('firewall.list_pushed', $list, ['status' => $status, 'message' => $message, 'by' => $actor?->id]);

        return ['ok' => $status !== 'failed', 'message' => "{$list->name}: {$message}"];
    }
}
