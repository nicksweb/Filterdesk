<?php

namespace App\Services\Policy;

use App\Enums\EntrySource;
use App\Enums\ListKind;
use App\Enums\ListScope;
use App\Enums\RequestStatus;
use App\Models\PolicyList;
use App\Models\WebsiteRequest;
use App\Services\SettingsRepository;
use Illuminate\Support\Carbon;

/**
 * Idempotent upgrade step for the audience-aware policy model:
 * - creates default filter policies and lists,
 * - moves the legacy single master allow/block lists into site-wide lists,
 * - creates entries for requests that were approved before entries existed.
 */
class PolicyBackfill
{
    public function __construct(private readonly PolicyEngine $engine, private readonly SettingsRepository $settings) {}

    public const WINDOW_FIX_FLAG = 'fixes.request_windows_utc';

    public function run(): string
    {
        $fixed = $this->convertLegacyRequestWindows();
        $this->engine->ensureDefaults();
        $imported = $this->importLegacyMasterLists();
        $granted = 0;

        WebsiteRequest::query()->whereIn('status', RequestStatus::granted())->whereDoesntHave('policyEntries')
            ->where(fn ($query) => $query->whereNull('requested_until')->orWhere('requested_until', '>', now()))
            ->each(function (WebsiteRequest $request) use (&$granted): void {
                $this->engine->grant($request, $request->effectiveDomains(), $request->effectiveScope(), $request->approvedBy);
                $granted++;
            });

        return "Defaults ensured; {$fixed} request window(s) converted to UTC; {$imported} legacy entries imported; {$granted} approved request(s) placed into lists.";
    }

    /**
     * Before 0.2.0, temporary windows were saved as the school's wall-clock
     * time in a UTC column. Convert them once, then mirror onto policy entries.
     */
    private function convertLegacyRequestWindows(): int
    {
        if ($this->settings->get(self::WINDOW_FIX_FLAG, false)) {
            return 0;
        }

        $timezone = $this->settings->timezone();
        $count = 0;
        WebsiteRequest::query()->where(fn ($query) => $query->whereNotNull('requested_from')->orWhereNotNull('requested_until'))
            ->each(function (WebsiteRequest $request) use ($timezone, &$count): void {
                $convert = fn (?string $raw) => $raw === null ? null : Carbon::parse($raw, $timezone)->utc();
                $from = $convert($request->getRawOriginal('requested_from'));
                $until = $convert($request->getRawOriginal('requested_until'));
                $request->forceFill([
                    'requested_from' => $from,
                    'requested_until' => $until,
                    'expires_at' => $request->expires_at ? $until : null,
                ])->saveQuietly();
                $request->policyEntries()->update(['starts_at' => $from, 'expires_at' => $until]);
                $count++;
            });

        $this->settings->set(self::WINDOW_FIX_FLAG, true);

        return $count;
    }

    private function importLegacyMasterLists(): int
    {
        $legacy = $this->settings->get('policy', []);
        if (! is_array($legacy) || ($legacy['migrated'] ?? false)) {
            return 0;
        }

        $count = 0;
        foreach (['master_allowlist' => ListKind::Allow, 'master_blocklist' => ListKind::Block] as $key => $kind) {
            $list = PolicyList::query()->where('scope', ListScope::Site)->where('kind', $kind)->orderBy('id')->first();
            foreach ($legacy[$key] ?? [] as $domain) {
                if (is_string($domain) && $list) {
                    $this->engine->upsertEntry($list, ltrim($domain, '*.'), ['source' => EntrySource::Import, 'note' => 'Migrated from the master policy list.']);
                    $count++;
                }
            }
        }

        if ($legacy !== []) {
            $this->settings->set('policy', $legacy + ['migrated' => true]);
        }

        return $count;
    }
}
