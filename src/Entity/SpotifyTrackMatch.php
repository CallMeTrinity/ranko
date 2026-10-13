<?php

namespace App\Entity;

use App\Repository\SpotifyTrackMatchRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Notre titre chez Spotify, trouvé une fois pour toutes les synchros.
 *
 * Pas une ExternalReference : deux de nos titres peuvent tomber sur le même titre
 * Spotify (édition deluxe, réédition), ce que la contrainte d'unicité des références
 * refuserait. Une absence de résultat est gardée aussi (uri null), pour ne pas relancer
 * la recherche à chaque synchro.
 */
#[ORM\Entity(repositoryClass: SpotifyTrackMatchRepository::class)]
class SpotifyTrackMatch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\OneToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Track $track,
        /** "spotify:track:…", null si Spotify n'a pas ce titre. */
        #[ORM\Column(length: 64, nullable: true)]
        private ?string $uri,
        #[ORM\Column]
        private \DateTimeImmutable $checkedAt,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTrack(): Track
    {
        return $this->track;
    }

    public function getUri(): ?string
    {
        return $this->uri;
    }

    public function getCheckedAt(): \DateTimeImmutable
    {
        return $this->checkedAt;
    }

    public function update(?string $uri, \DateTimeImmutable $checkedAt): static
    {
        $this->uri = $uri;
        $this->checkedAt = $checkedAt;

        return $this;
    }
}
