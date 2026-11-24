<?php

namespace App\Services\Policy;

use App\Enums\ListKind;
use App\Models\FilterPolicy;
use App\Models\PolicyEntry;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SettingsRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Entries that look like two hostnames typed without a line break, such as
 * "wistia.comfast.wistia.net" (wistia.com + fast.wistia.net): a label that
 * starts with a top-level domain and goes on, where the name before it
 * comes back after it. Such an entry matches nothing, so it is flagged with
 * Split (into the two hostnames) or Keep.
 */
class RunTogetherEntries
{
    public const KEPT_KEY = 'policy.run_together_kept';

    private const ENDINGS = ['com', 'net', 'org', 'edu', 'gov', 'info', 'io', 'co', 'au', 'uk', 'nz'];

    public function __construct(private readonly SettingsRepository $settings, private readonly AuditLogger $audit) {}

    /** @return array{0: string, 1: string}|null the two hostnames it looks like */
    public static function split(string $domain): ?array
    {
        $labels = explode('.', strtolower(trim($domain)));
        $count = count($labels);
        for ($index = 1; $index < $count - 1; $index++) {
            foreach (self::ENDINGS as $ending) {
                $label = $labels[$index];
                if (strlen($label) <= strlen($ending) + 1 || ! str_starts_with($label, $ending)) {
                    continue;
                }
                $right = [substr($label, strlen($ending)), ...array_slice($labels, $index + 1)];
                // The name before the ending comes back after it ("wistia" … "wistia.net"): two entries run together.
                if (in_array($labels[$index - 1], array_slice($right, 1), true)) {
                    return [implode('.', [...array_slice($labels, 0, $index), $ending]), implode('.', $right)];
                }
            }
        }

        return null;
    }

    /**
     * Run-together entries in a policy's own lists and in what UniFi has for
     * it, minus the ones kept on purpose.
     *
     * @return Collection<int, array{domain: string, parts: array{0: string, 1: string}, entry: ?PolicyEntry}>
     */
    public function forPolicy(FilterPolicy $policy): Collection
    {
        $kept = (array) $this->settings->get(self::KEPT_KEY, []);
        $entries = PolicyEntry::query()->whereHas('policyList', fn ($query) => $query->where('filter_policy_id', $policy->id))->with('policyList')->get(['id', 'policy_list_id', 'domain'])->keyBy('domain');
        $domains = collect([...$entries->keys(), ...($policy->current_allowlist ?? []), ...($policy->current_blocklist ?? [])])->unique();

        return $domains->reject(fn (string $domain) => in_array($domain, $kept, true))
            ->map(fn (string $domain) => ($parts = self::split($domain)) ? ['domain' => $domain, 'parts' => $parts, 'entry' => $entries->get($domain)] : null)
            ->filter()->values();
    }

    /**
     * Replace the entry with the two hostnames in the same list. The old one
     * is taken out of UniFi at the next publish.
     *
     * @return array{0: string, 1: string}
     */
    public function splitEntry(PolicyEntry $entry, User $actor): array
    {
        $parts = self::split($entry->domain) ?? throw new \InvalidArgumentException('That entry does not look like two hostnames.');
        DB::transaction(function () use ($entry, $parts): void {
            foreach ($parts as $domain) {
                PolicyEntry::query()->firstOrCreate(['policy_list_id' => $entry->policy_list_id, 'domain' => $domain],
                    $entry->only(['source', 'note', 'added_by_id', 'starts_at', 'expires_at', 'hours']));
            }
            // Mark the old hostname as Filterdesk's in every filter that has it, so publishing removes it.
            $kind = $entry->policyList->kind === ListKind::Allow ? 'allow' : 'block';
            foreach (FilterPolicy::query()->whereNotNull('provider_reference')->get() as $policy) {
                if (in_array($entry->domain, $kind === 'allow' ? ($policy->current_allowlist ?? []) : ($policy->current_blocklist ?? []), true)) {
                    $managed = $policy->managed();
                    $managed[$kind] = array_values(array_unique([...$managed[$kind], $entry->domain]));
                    $policy->update(['controller_managed' => $managed]);
                }
            }
            $entry->delete();
        });
        $this->audit->log('policy.entry_split', $entry->policyList, ['domain' => $entry->domain, 'into' => $parts, 'by' => $actor->id]);

        return $parts;
    }

    public function keep(string $domain, User $actor): void
    {
        $this->settings->set(self::KEPT_KEY, array_values(array_unique([...(array) $this->settings->get(self::KEPT_KEY, []), $domain])));
        $this->audit->log('policy.entry_kept', null, ['domain' => $domain, 'by' => $actor->id]);
    }
}
