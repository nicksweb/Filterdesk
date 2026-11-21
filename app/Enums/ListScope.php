<?php

namespace App\Enums;

enum ListScope: string
{
    /** Applies to every enabled filter policy. */
    case Site = 'site';

    /** Applies to every filter policy of the list's network type (stored in the audience column). */
    case Audience = 'audience';

    /** Applies to a single filter policy. */
    case Policy = 'policy';

    public function label(): string
    {
        return match ($this) {
            self::Site => 'Site-wide',
            self::Audience => 'Network type',
            self::Policy => 'Single policy',
        };
    }
}
