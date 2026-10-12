<?php

namespace App\Enum;

enum CatalogItemKind: string
{
    case Artist = 'artist';
    case Album = 'album';
    case Track = 'track';

    public function label(): string
    {
        return match ($this) {
            self::Artist => 'Artistes',
            self::Album => 'Albums',
            self::Track => 'Titres',
        };
    }

    /**
     * Types qu'on peut classer dans un périmètre de ce type : les albums et les titres
     * d'un artiste, les titres d'un album. Réciproque de allowedScopeKinds().
     *
     * @return list<self>
     */
    public function rankableKinds(): array
    {
        return array_values(array_filter(self::cases(), fn (self $kind): bool => \in_array($this, $kind->allowedScopeKinds(), true)));
    }

    /**
     * Périmètres valides pour un classement de ce type : un titre peut être classé
     * dans tout le catalogue, chez un artiste ou dans un album ; un artiste, seulement
     * dans tout le catalogue.
     *
     * @return list<self>
     */
    public function allowedScopeKinds(): array
    {
        return match ($this) {
            self::Artist => [],
            self::Album => [self::Artist],
            self::Track => [self::Artist, self::Album],
        };
    }
}
