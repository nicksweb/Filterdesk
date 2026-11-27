<?php

namespace App\Services\Network;

use App\Models\FirewallList;
use App\Models\IntegrationConfig;

/**
 * Whether Filterdesk may write firewall rules (create, delete, recreate,
 * reorder) or only keeps its UniFi IP lists up to date and leaves the rules
 * to IT. Settings → Network provider → Firewall rules. Reading rules (for
 * the firewall checks) is never affected.
 *
 * Without a saved choice, an install that already has rules made by
 * Filterdesk keeps managing them; any other install is "IP lists only".
 */
class FirewallRuleMode
{
    public const MANAGE = 'manage';

    public const LISTS_ONLY = 'lists_only';

    public static function current(?IntegrationConfig $integration): string
    {
        $mode = $integration?->configuration['firewall_rules'] ?? null;
        if (in_array($mode, [self::MANAGE, self::LISTS_ONLY], true)) {
            return $mode;
        }

        return FirewallList::query()->get(['id', 'rules'])->contains(fn (FirewallList $list) => ! empty($list->rules)) ? self::MANAGE : self::LISTS_ONLY;
    }

    public static function allowsRules(?IntegrationConfig $integration): bool
    {
        return self::current($integration) === self::MANAGE;
    }

    /** Refuse a rule write, so no path can create, change, reorder or delete a rule in "IP lists only". */
    public static function guard(?IntegrationConfig $integration, string $what): void
    {
        if (! self::allowsRules($integration)) {
            throw new ProviderRequestFailed("Filterdesk is set to keep IP lists only, so it doesn't {$what} (Settings → Network provider → Firewall rules).");
        }
    }
}
