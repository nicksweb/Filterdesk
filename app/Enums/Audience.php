<?php

namespace App\Enums;

/**
 * Who a network or filter policy serves. Requests and lists are matched to
 * policies by audience, so a "Staff and students" approval reaches every
 * policy tagged staff or student, whatever the school calls them.
 */
enum Audience: string
{
    case Staff = 'staff';
    case Student = 'student';
    case Guest = 'guest';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Staff => 'Staff access',
            self::Student => 'Student access',
            self::Guest => 'Guest access',
            self::Other => 'Other',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }
}
