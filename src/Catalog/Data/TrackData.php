<?php

namespace App\Catalog\Data;

final readonly class TrackData
{
    public function __construct(
        public string $externalId,
        public string $title,
        public int $discNumber,
        public int $position,
        public ?int $durationMs = null,
        /** Premier ISRC de l'enregistrement : un enregistrement peut en avoir plusieurs (rééditions). */
        public ?string $isrc = null,
    ) {
    }
}
