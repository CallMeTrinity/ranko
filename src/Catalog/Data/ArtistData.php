<?php

namespace App\Catalog\Data;

final readonly class ArtistData
{
    public function __construct(
        public string $externalId,
        public string $name,
        public ?string $sortName = null,
        public ?string $disambiguation = null,
        public ?string $country = null,
    ) {
    }
}
