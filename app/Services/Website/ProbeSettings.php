<?php

namespace App\Services\Website;

use App\Models\User;
use App\Services\SettingsRepository;

/**
 * Probe options kept in the app rather than in .env: extra DNS answers that
 * mean "blocked" (sent with every check, on top of the probe's own list), and
 * who sees page previews in website checks. A preview is a screenshot and the
 * page title, so by default only IT sees them: a student or staff member who
 * checks an inappropriate site gets the answer without the page.
 */
class ProbeSettings
{
    public const BLOCK_ADDRESSES_KEY = 'probes.block_addresses';

    public const PREVIEWS_KEY = 'checks.previews';

    public const METHOD_KEY = 'checks.method';

    /** @var array<string, string> how a check decides */
    public const METHODS = [
        'browser' => 'Load the page (DNS, block pages, HTTPS inspection, failing parts)',
        'dns' => 'DNS only (fast; the page is never loaded)',
    ];

    /** @var array<string, string> who sees previews */
    public const PREVIEWS = [
        'it' => 'IT only',
        'staff' => 'Staff and IT',
        'everyone' => 'Everyone, students too',
    ];

    public function __construct(private readonly SettingsRepository $settings) {}

    /** @return list<string> */
    public function blockAddresses(): array
    {
        return array_values(array_filter(array_map(fn ($address) => strtolower(trim((string) $address)), (array) $this->settings->get(self::BLOCK_ADDRESSES_KEY, [])),
            fn (string $address) => filter_var($address, FILTER_VALIDATE_IP) !== false));
    }

    /** @param list<string> $addresses */
    public function saveBlockAddresses(array $addresses): void
    {
        $valid = array_filter(array_map(fn ($address) => strtolower(trim((string) $address)), $addresses), fn (string $address) => filter_var($address, FILTER_VALIDATE_IP) !== false);
        $this->settings->set(self::BLOCK_ADDRESSES_KEY, array_values(array_unique($valid)));
    }

    /** DNS-only checks: the probes answer from DNS and never load the page (so no previews either). */
    public function dnsOnly(): bool
    {
        return $this->settings->get(self::METHOD_KEY, 'browser') === 'dns';
    }

    public function previews(): string
    {
        $value = (string) $this->settings->get(self::PREVIEWS_KEY, 'it');

        return array_key_exists($value, self::PREVIEWS) ? $value : 'it';
    }

    public function previewsFor(?User $user): bool
    {
        if ($this->dnsOnly()) {
            return false;
        }

        return match ($this->previews()) {
            'everyone' => true,
            'staff' => $user !== null && ! $user->isStudent(),
            default => $user?->isIt() ?? false,
        };
    }
}
