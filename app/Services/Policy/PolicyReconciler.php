<?php

namespace App\Services\Policy;

use App\Enums\RequestStatus;
use App\Models\WebsiteRequest;
use App\Services\SettingsRepository;

class PolicyReconciler
{
    public function __construct(private readonly SettingsRepository $settings) {}

    /** @return array<string, mixed> */
    public function report(): array
    {
        $policy = $this->settings->get('policy', []);
        $masterAllow = $this->sorted($policy['master_allowlist'] ?? []);
        $masterBlock = $this->sorted($policy['master_blocklist'] ?? []);
        $currentAllow = $this->sorted($policy['current_allowlist'] ?? []);
        $currentBlock = $this->sorted($policy['current_blocklist'] ?? []);

        $approved = WebsiteRequest::query()
            ->whereIn('status', [RequestStatus::Approved, RequestStatus::ActionRequired, RequestStatus::Active])
            ->where(fn ($query) => $query->whereNull('requested_from')->orWhere('requested_from', '<=', now()))
            ->where(fn ($query) => $query->whereNull('requested_until')->orWhere('requested_until', '>', now()))
            ->pluck('domains')->flatten()->all();

        $pending = WebsiteRequest::query()
            ->whereIn('status', [RequestStatus::Pending, RequestStatus::NeedsInformation])
            ->pluck('domains')->flatten()->all();

        $desiredAllow = $this->sorted([...$masterAllow, ...$approved]);
        $conflicts = array_values(array_intersect($desiredAllow, $masterBlock));
        $desiredBlock = array_values(array_diff($masterBlock, $desiredAllow));

        return [
            'master_allow' => $masterAllow,
            'master_block' => $masterBlock,
            'current_allow' => $currentAllow,
            'current_block' => $currentBlock,
            'desired_allow' => $desiredAllow,
            'desired_block' => $desiredBlock,
            'pending' => $this->sorted($pending),
            'conflicts' => $conflicts,
            'allow_add' => array_values(array_diff($desiredAllow, $currentAllow)),
            'allow_review_remove' => array_values(array_diff($currentAllow, $desiredAllow)),
            'block_add' => array_values(array_diff($desiredBlock, $currentBlock)),
            'block_remove' => array_values(array_diff($currentBlock, $desiredBlock)),
            'compared_at' => $policy['compared_at'] ?? null,
        ];
    }

    /** @param array<string, mixed> $changes */
    public function update(array $changes): void
    {
        $policy = $this->settings->get('policy', []);
        $this->settings->set('policy', array_merge($policy, $changes));
    }

    /** @param array<int, mixed> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        $values = array_values(array_unique(array_filter($values, 'is_string')));
        sort($values);

        return $values;
    }
}
