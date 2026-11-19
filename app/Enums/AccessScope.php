<?php

namespace App\Enums;

enum AccessScope: string
{
    case Staff = 'staff';
    case StaffAndStudents = 'staff_students';

    /** Every enabled policy, including guest and other networks. IT-only. */
    case SiteWide = 'site';

    public function label(): string
    {
        return match ($this) {
            self::Staff => 'Staff only',
            self::StaffAndStudents => 'Staff and students',
            self::SiteWide => 'Site-wide (all networks)',
        };
    }

    /** @return list<Audience> */
    public function audiences(): array
    {
        return match ($this) {
            self::Staff => [Audience::Staff],
            self::StaffAndStudents => [Audience::Staff, Audience::Student],
            self::SiteWide => [],
        };
    }

    /** @return list<self> */
    public static function requestable(): array
    {
        return [self::Staff, self::StaffAndStudents];
    }
}
