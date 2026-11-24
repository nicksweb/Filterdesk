<?php

namespace App\Services;

/**
 * Where staff and students get help: the school helpdesk's contact details
 * and a link to the IT team's own support article, shown in a "Need help?"
 * box and on the How to page. Set under Settings → Help and support.
 */
class Support
{
    public const KEY = 'support';

    /** @var array<string, array{label: string, max: int, placeholder: string}> */
    public const FIELDS = [
        'name' => ['label' => 'Helpdesk name', 'max' => 80, 'placeholder' => 'IT Service Desk'],
        'email' => ['label' => 'Email', 'max' => 120, 'placeholder' => 'servicedesk@example.edu.au'],
        'phone' => ['label' => 'Phone', 'max' => 40, 'placeholder' => '07 0000 0000 (ext. 123)'],
        'hours' => ['label' => 'Opening hours', 'max' => 80, 'placeholder' => 'Mon–Fri 7:30–16:30'],
        'location' => ['label' => 'Where to find you', 'max' => 120, 'placeholder' => 'Library, level 1'],
        'note' => ['label' => 'Note', 'max' => 300, 'placeholder' => 'For urgent problems during class, ask your teacher to call us.'],
        'article_url' => ['label' => 'Support article link', 'max' => 500, 'placeholder' => 'https://intranet.example.edu.au/it/website-access'],
        'article_label' => ['label' => 'Support article title', 'max' => 100, 'placeholder' => 'How to use website checks and requests'],
    ];

    public function __construct(private readonly SettingsRepository $settings) {}

    /** @return array<string, string> */
    public function all(): array
    {
        $stored = (array) $this->settings->get(self::KEY, []);

        return collect(self::FIELDS)->mapWithKeys(fn (array $field, string $key) => [$key => trim((string) ($stored[$key] ?? ''))])->all();
    }

    /** Anything to show in the "Need help?" box. */
    public function hasContact(): bool
    {
        $support = $this->all();

        return collect(['email', 'phone', 'location', 'article_url'])->contains(fn (string $key) => $support[$key] !== '');
    }

    /** @param array<string, ?string> $values */
    public function save(array $values): void
    {
        $this->settings->set(self::KEY, collect(self::FIELDS)->mapWithKeys(fn (array $field, string $key) => [$key => trim((string) ($values[$key] ?? ''))])->filter()->all());
    }
}
