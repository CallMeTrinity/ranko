<?php

namespace App\Catalog\Data;

use App\Enum\AlbumType;

final readonly class AlbumData
{
    /**
     * @param list<string> $secondaryTypes
     */
    public function __construct(
        public string $externalId,
        public string $title,
        public AlbumType $type,
        public array $secondaryTypes = [],
        public ?string $releaseDate = null,
    ) {
    }

    /**
     * Album "canonique" : ni live, ni compilation, ni remix...
     */
    public function isMainRelease(): bool
    {
        return [] === $this->secondaryTypes && \in_array($this->type, [AlbumType::Album, AlbumType::Ep], true);
    }
}
