<?php

namespace App\Services\Requests;

use App\Models\User;
use App\Models\WebsiteRequest;
use App\Services\SettingsRepository;

/**
 * What to do once access is approved. The filter works on website names
 * (DNS), so a computer can keep the old "blocked" answer for a few minutes;
 * the guide tells people to close the browser and, if needed, restart.
 * Shown to staff (students only when turned on), editable under
 * Settings → Approval policy.
 */
class AccessGuide
{
    public const DEFAULTS = [
        'enabled' => true,
        'students' => false,
        'title' => 'Your access is on its way',
        'intro' => 'The school filters websites by name, so your computer can remember the old "blocked" answer for a few minutes after access is approved. If the site still looks blocked:',
        'steps' => "Wait a minute or two while the change reaches the network.\nClose every browser window (not just the tab), then open the browser again. Or try a private (incognito) window.\nStill blocked? Restart your computer, or turn Wi-Fi off and on again.\nStill blocked after 15 minutes? Reply on your request and IT will check it.",
    ];

    public function __construct(private readonly SettingsRepository $settings) {}

    /** @return array{enabled: bool, students: bool, title: string, intro: string, steps: string} */
    public function settings(): array
    {
        $saved = (array) $this->settings->get('access_guide', []);

        return array_merge(self::DEFAULTS, array_filter($saved, fn ($value) => $value !== null && $value !== ''));
    }

    /** @return list<string> */
    public function steps(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $this->settings()['steps']) ?: [])));
    }

    /** Whether this person should see the guide for this request. */
    public function appliesTo(WebsiteRequest $request, ?User $user = null): bool
    {
        $settings = $this->settings();
        $user ??= $request->requester;

        return $settings['enabled'] && ! $request->isBlock() && $request->status->isGranted()
            && $user !== null && ($settings['students'] || ! $user->isStudent());
    }
}
