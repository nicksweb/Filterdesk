<?php

namespace App\Services;

/**
 * Text people see that a school may want in its own words: the dashboard
 * heading, the sign-in page and the website check page. Blank fields use the
 * defaults, which are written for students and staff, not IT.
 */
class Wording
{
    public const KEY = 'wording';

    /** @var array<string, array{label: string, default: string, max: int}> */
    public const FIELDS = [
        'dashboard_eyebrow' => ['label' => 'Dashboard heading label', 'default' => 'Web filtering', 'max' => 60],
        'dashboard_intro' => ['label' => 'Dashboard introduction', 'default' => 'Check whether a website works at school, or ask for one to be allowed or blocked.', 'max' => 200],
        'login_eyebrow' => ['label' => 'Sign-in label', 'default' => 'Sign in', 'max' => 60],
        'login_title' => ['label' => 'Sign-in title', 'default' => 'Welcome', 'max' => 60],
        'login_intro' => ['label' => 'Sign-in introduction', 'default' => 'Sign in with your school account to check whether a website works at school, or to ask for one to be allowed.', 'max' => 200],
        'check_intro_student' => ['label' => 'Website check introduction (students)', 'default' => 'See whether a website works on the student network, then ask for it if it is blocked.', 'max' => 200],
        'check_intro_staff' => ['label' => 'Website check introduction (staff)', 'default' => 'See whether a website works on each school network, then ask IT to allow or block it.', 'max' => 200],
        'check_hint' => ['label' => 'Hint under the website box', 'default' => 'Each check looks at the school networks right now, so the answer is up to date.', 'max' => 200],
    ];

    public function __construct(private readonly SettingsRepository $settings) {}

    public function get(string $key): string
    {
        $stored = (array) $this->settings->get(self::KEY, []);
        $value = trim((string) ($stored[$key] ?? ''));

        return $value !== '' ? $value : (self::FIELDS[$key]['default'] ?? '');
    }

    /** @return array<string, string> what is saved (blank means the default) */
    public function saved(): array
    {
        return array_map('strval', (array) $this->settings->get(self::KEY, []));
    }

    /** @param array<string, ?string> $values */
    public function save(array $values): void
    {
        $this->settings->set(self::KEY, collect(self::FIELDS)->mapWithKeys(fn (array $field, string $key) => [$key => trim((string) ($values[$key] ?? ''))])->filter()->all());
    }
}
