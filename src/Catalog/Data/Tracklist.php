<?php

namespace App\Catalog\Data;

final readonly class Tracklist
{
    /**
     * @param string|null     $editionId édition dont viennent les titres, null si l'album n'en a aucune
     * @param list<TrackData> $tracks
     */
    public function __construct(
        public ?string $editionId,
        public array $tracks,
    ) {
    }
}
