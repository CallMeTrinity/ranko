<?php

namespace App\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Un message par album plutôt qu'un seul pour toute la discographie : chaque album
 * fait deux appels à MusicBrainz, un échec n'oblige à rejouer que celui-là.
 */
#[AsMessage('async')]
final readonly class ImportAlbumTracks
{
    public function __construct(
        public int $albumId,
    ) {
    }
}
