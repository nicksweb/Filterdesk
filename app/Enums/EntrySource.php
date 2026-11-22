<?php

namespace App\Enums;

enum EntrySource: string
{
    case Manual = 'manual';
    case Request = 'request';
    case Import = 'import';
    case Feed = 'feed';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Added by IT',
            self::Request => 'Approved request',
            self::Import => 'Imported',
            self::Feed => 'Blocklist feed',
        };
    }
}
