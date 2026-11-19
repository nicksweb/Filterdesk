<?php

namespace App\Enums;

enum AccessScope: string
{
    case Staff = 'staff';
    case StaffAndStudents = 'staff_students';

    public function label(): string
    {
        return match ($this) {
            self::Staff => 'Staff only',
            self::StaffAndStudents => 'Staff and students',
        };
    }
}
