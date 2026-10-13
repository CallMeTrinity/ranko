<?php

namespace App\Spotify;

final readonly class SpotifyTokens
{
    public function __construct(
        public string $accessToken,
        /** Absent d'un rafraîchissement quand Spotify garde le même. */
        public ?string $refreshToken,
        public int $expiresIn,
    ) {
    }

    public function expiresAt(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify(sprintf('+%d seconds', $this->expiresIn));
    }
}
