<?php

namespace App\Services;

use App\Models\CalendarPeriod;
use App\Models\FilterPolicy;
use App\Models\NetworkAssignment;
use App\Models\NetworkProfile;
use App\Models\User;
use App\Services\Domains\DomainCategorizer;
use App\Services\Network\NetworkProviderManager;
use App\Services\Policy\BaselineGuard;
use App\Services\Website\ProbeDiagnostics;

/**
 * What a new install still needs after the wizard, worked out from the
 * system itself where it can be. Items can be marked done (or the whole list
 * hidden) for things the app cannot see, such as backups.
 */
class SetupChecklist
{
    public function __construct(private readonly SettingsRepository $settings) {}

    /** @return list<array{key: string, label: string, detail: string, link: string, done: bool, optional: bool}> */
    public function items(): array
    {
        $dismissed = (array) $this->settings->get('setup.done', []);
        $integration = rescue(fn () => app(NetworkProviderManager::class)->integration(), null, false);
        $profiles = NetworkProfile::query()->where('enabled', true)->get();

        $items = [
            ['key' => 'unifi', 'label' => 'Bring your UniFi filters in', 'optional' => false,
                'detail' => $integration?->provider === 'mock' ? 'Connect UniFi under Settings → Network provider, then run Guided setup.' : 'Guided setup imports every content filter, list and network in one step.',
                'link' => $integration?->provider === 'mock' ? route('admin.settings.show', 'network') : route('admin.policy.setup.show'),
                'done' => FilterPolicy::query()->whereNotNull('provider_reference')->exists()],
            ['key' => 'probes', 'label' => 'Put the probes on their VLANs', 'optional' => false,
                'detail' => 'Each probe checks websites from its own network; the checklist shows what is left.',
                'link' => route('admin.settings.show', ['section' => 'profiles', 'refresh' => 1]),
                'done' => $profiles->isNotEmpty() && $profiles->every(fn (NetworkProfile $profile) => (app(ProbeDiagnostics::class)->cached($profile)['state'] ?? null) === 'ok')],
            ['key' => 'baseline', 'label' => 'Decide on networks without a filter', 'optional' => false,
                'detail' => 'Every network gets at least Students filtering unless you allow it to stay unfiltered, with a reason.',
                'link' => route('admin.policy.networks.index').'#baseline',
                'done' => filled($this->settings->get(BaselineGuard::STARTED_KEY)) && NetworkAssignment::query()->whereNotNull('unfiltered_reason')->whereNull('unfiltered_reviewed_at')->doesntExist()],
            ['key' => 'second_officer', 'label' => 'Add a second IT officer', 'optional' => false,
                'detail' => 'Two-person approval for site-wide changes and sensitive categories needs two enabled IT officers.',
                'link' => route('admin.users.index'), 'done' => User::query()->itStaff()->count() >= 2],
            ['key' => 'calendar', 'label' => 'Enter the school calendar', 'optional' => true,
                'detail' => 'Terms, holidays, exam periods and bell times, for calendar schedules and exam mode.',
                'link' => route('admin.settings.show', 'calendar'), 'done' => CalendarPeriod::query()->where('kind', 'term')->exists()],
            ['key' => 'categories', 'label' => 'Turn on website categories', 'optional' => true,
                'detail' => 'Needed for sensitive-category approvals, smart approval and the category guard.',
                'link' => route('admin.settings.show', 'categories'), 'done' => app(DomainCategorizer::class)->enabled()],
            ['key' => 'active', 'label' => 'Switch pushing on', 'optional' => true,
                'detail' => 'Read-only until you are happy; Active mode lets the app publish changes to UniFi.',
                'link' => route('admin.settings.show', 'network'), 'done' => $integration?->mode === 'active'],
            ['key' => 'backups', 'label' => 'Schedule backups', 'optional' => false,
                'detail' => 'Run bin/backup daily from cron on the host and copy backups/ elsewhere (docs/SETUP.md, Ongoing operation). Mark this done once it is in place.',
                'link' => 'https://github.com/nicksweb/Filterdesk/blob/main/docs/SETUP.md#12-ongoing-operation', 'done' => false],
        ];

        return array_map(fn (array $item) => ['done' => $item['done'] || in_array($item['key'], $dismissed, true)] + $item, $items);
    }

    public function visible(): bool
    {
        return ! $this->settings->get('setup.hidden', false) && collect($this->items())->contains('done', false);
    }

    public function markDone(string $key): void
    {
        $this->settings->set('setup.done', array_values(array_unique([...(array) $this->settings->get('setup.done', []), $key])));
    }

    public function hide(): void
    {
        $this->settings->set('setup.hidden', true);
    }
}
