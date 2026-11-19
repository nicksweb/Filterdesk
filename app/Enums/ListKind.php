<?php

namespace App\Enums;

enum ListKind: string
{
    case Allow = 'allow';
    case Block = 'block';

    public function label(): string
    {
        return match ($this) {
            self::Allow => 'Allow list',
            self::Block => 'Block list',
        };
    }
}
