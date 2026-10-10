<?php

namespace App\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage('async')]
final readonly class ImportArtistDiscography
{
    public function __construct(
        public int $artistId,
    ) {
    }
}
