<?php

namespace App\Enum;

enum Visibility: string
{
    case Private = 'private';
    case Public = 'public';

    public function label(): string
    {
        return match ($this) {
            self::Private => 'Privé',
            self::Public => 'Public',
        };
    }
}
