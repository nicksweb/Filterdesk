<?php

namespace App\Services\Approval;

use App\Enums\ListScope;
use App\Models\PolicyList;
use App\Models\User;
use App\Models\WebsiteRequest;
use App\Services\SettingsRepository;
use App\Support\Reach;

/**
 * Which decisions need two IT officers: approving site-wide access or a
 * site in a sensitive category, and hand-made changes to site-wide lists.
 * The officer who gives the first approval cannot give the second.
 *
 * With fewer than two enabled IT officers the rule cannot be met, so it is
 * skipped and the decision notes why.
 */
class TwoPersonRule
{
    /** Category names (Cloudflare or UniFi) treated as sensitive by default. */
    public const DEFAULT_SENSITIVE = [
        'Adult Themes', 'Pornography', 'Nudity', 'Gambling', 'Drugs', 'Violence', 'Weapons', 'Hate & Intolerance',
        'Dating & Relationships', 'Proxy & Filter Avoidance', 'Self Harm', 'Questionable Content',
    ];

    public function __construct(private readonly SettingsRepository $settings) {}

    public function siteWideEnabled(): bool
    {
        return (bool) $this->settings->get('approval.two_person_site_wide', true);
    }

    /**
     * An officer may approve alone when nobody else is available, giving a
     * reason; the other officers are told and the override is audited.
     */
    public function overrideAllowed(): bool
    {
        return (bool) $this->settings->get('approval.two_person_override', true);
    }

    /** @return list<string> */
    public function sensitiveCategories(): array
    {
        return array_values((array) $this->settings->get('approval.sensitive_categories', self::DEFAULT_SENSITIVE));
    }

    /** Two different enabled IT officers exist, so a second approval is possible. */
    public function possible(): bool
    {
        return User::query()->itStaff()->count() >= 2;
    }

    /**
     * Why approving this request needs a second officer; empty when it does not.
     *
     * @param  list<string>  $domains
     * @return list<string>
     */
    public function reasonsForRequest(WebsiteRequest $request, Reach $scope, array $domains): array
    {
        $reasons = [];
        if ($scope->siteWide && $this->siteWideEnabled()) {
            $reasons[] = 'site-wide '.($request->isBlock() ? 'block' : 'access');
        }
        $sensitive = array_map('mb_strtolower', $this->sensitiveCategories());
        $found = [];
        foreach ((array) $request->domain_categories as $domain => $lookup) {
            if (! in_array($domain, $domains, true) && ! in_array($domain, $request->domains, true)) {
                continue;
            }
            $names = [...(array) ($lookup['categories'] ?? []), ...(array) ($lookup['risks'] ?? []),
                ...array_column((array) ($lookup['unifi'] ?? []), 'label'), ...array_column((array) ($lookup['unifi'] ?? []), 'id')];
            foreach ($names as $name) {
                $key = mb_strtolower(str_replace('_', ' ', (string) $name));
                if (in_array($key, $sensitive, true) || in_array(mb_strtolower((string) $name), $sensitive, true)) {
                    $found[(string) $name] = true;
                }
            }
        }
        if ($found) {
            $reasons[] = 'sensitive category ('.implode(', ', array_keys($found)).')';
        }

        return $reasons;
    }

    /** A hand-made change to this list waits for a second officer. */
    public function appliesToList(PolicyList $list): bool
    {
        return $list->scope === ListScope::Site && $this->siteWideEnabled() && $this->possible();
    }
}
