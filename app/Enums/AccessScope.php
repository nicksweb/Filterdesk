<?php

namespace App\Enums;

enum AccessScope: string
{
    case Staff = 'staff';
    case StaffAndStudents = 'staff_students';
    case Students = 'students';

    /** Every enabled policy, including guest and other networks. IT-only. */
    case SiteWide = 'site';

    public function label(): string
    {
        return match ($this) {
            self::Staff => 'Staff only',
            self::StaffAndStudents => 'Staff and students',
            self::Students => 'Students only',
            self::SiteWide => 'Site-wide (all networks)',
        };
    }

    /** @return list<Audience> */
    public function audiences(): array
    {
        return match ($this) {
            self::Staff => [Audience::Staff],
            self::StaffAndStudents => [Audience::Staff, Audience::Student],
            self::Students => [Audience::Student],
            self::SiteWide => [],
        };
    }

    /**
     * Scopes a requester may choose. Blocks are usually wanted for students.
     *
     * @return list<self>
     */
    public static function requestable(bool $block = false): array
    {
        return $block ? [self::Students, self::StaffAndStudents, self::Staff] : [self::Staff, self::StaffAndStudents];
    }
}
