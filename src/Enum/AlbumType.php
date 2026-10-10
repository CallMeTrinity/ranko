<?php

namespace App\Enum;

enum AlbumType: string
{
    case Album = 'album';
    case Ep = 'ep';
    case Single = 'single';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Album => 'Album',
            self::Ep => 'EP',
            self::Single => 'Single',
            self::Other => 'Autre',
        };
    }
}
