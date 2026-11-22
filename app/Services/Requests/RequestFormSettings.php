<?php

namespace App\Services\Requests;

use App\Models\User;
use App\Services\SettingsRepository;

/**
 * How the request form asks for its free-text reason and category, per kind
 * of requester. The reason field's label and hint are configurable (schools
 * may ask for something else there later); it can be optional for staff and
 * required for students, and students get their own category list.
 */
class RequestFormSettings
{
    public const DEFAULTS = [
        'access_label' => 'Teaching or operational use case',
        'block_label' => 'Why should it be blocked?',
        'access_hint' => 'Describe the lesson, resource, audience, and why access is needed.',
        'block_hint' => 'For example: distracting game sites during class, unsafe content, or a site bypassing the filter.',
        'staff_required' => false,
        'student_required' => true,
        'min_length' => 10,
        'student_categories' => ['Schoolwork', 'Assignment research', 'Class activity', 'Other'],
    ];

    public function __construct(private readonly SettingsRepository $settings) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        return array_replace(self::DEFAULTS, array_filter((array) $this->settings->get('request_form', []), fn ($value) => $value !== null && $value !== ''));
    }

    public function label(bool $block): string
    {
        return (string) $this->all()[$block ? 'block_label' : 'access_label'];
    }

    public function hint(bool $block): string
    {
        return (string) $this->all()[$block ? 'block_hint' : 'access_hint'];
    }

    public function required(User $user): bool
    {
        return (bool) $this->all()[$user->isStudent() ? 'student_required' : 'staff_required'];
    }

    public function minLength(): int
    {
        return max(0, (int) $this->all()['min_length']);
    }

    /** @return list<string> */
    public function categories(User $user): array
    {
        if ($user->isStudent()) {
            return array_values((array) $this->all()['student_categories']);
        }

        return array_values((array) $this->settings->get('approval.categories', ['Teaching and learning', 'Research', 'Professional development', 'Administration', 'Other']));
    }
}
