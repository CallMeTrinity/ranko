<?php

namespace App\Entity;

use App\Repository\SpotifyPlaylistRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Playlist Spotify qui reflète un classement de titres. Synchro à sens unique : Ranko
 * fait foi, chaque synchro réécrit toute la playlist dans l'ordre du classement.
 *
 * Trois états : en attente (demandée, pas encore faite), à jour à syncedAt, ou en échec
 * (error). Une nouvelle demande efface l'erreur.
 */
#[ORM\Entity(repositoryClass: SpotifyPlaylistRepository::class)]
class SpotifyPlaylist
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $playlistId = null;

    /** Compte Spotify propriétaire : après une reconnexion avec un autre compte, on recrée la playlist. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $spotifyUserId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $url = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $snapshotId = null;

    #[ORM\Column]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $syncedAt = null;

    /** Titres du classement que Spotify n'a pas, absents de la playlist. */
    #[ORM\Column]
    private int $missingCount = 0;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $error = null;

    public function __construct(
        #[ORM\OneToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Ranking $ranking,
    ) {
        $this->requestedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRanking(): Ranking
    {
        return $this->ranking;
    }

    public function getPlaylistId(): ?string
    {
        return $this->playlistId;
    }

    public function getSpotifyUserId(): ?string
    {
        return $this->spotifyUserId;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function getSnapshotId(): ?string
    {
        return $this->snapshotId;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getSyncedAt(): ?\DateTimeImmutable
    {
        return $this->syncedAt;
    }

    public function getMissingCount(): int
    {
        return $this->missingCount;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function isPending(): bool
    {
        return null === $this->error && (null === $this->syncedAt || $this->syncedAt < $this->requestedAt);
    }

    /**
     * Le classement a changé depuis la dernière synchro.
     */
    public function isOutdated(): bool
    {
        return null !== $this->syncedAt && $this->ranking->getUpdatedAt() > $this->syncedAt;
    }

    public function request(\DateTimeImmutable $now): static
    {
        $this->requestedAt = $now;
        $this->error = null;

        return $this;
    }

    public function attach(string $playlistId, string $spotifyUserId, ?string $url): static
    {
        $this->playlistId = $playlistId;
        $this->spotifyUserId = $spotifyUserId;
        $this->url = $url;

        return $this;
    }

    public function markSynced(string $snapshotId, int $missingCount, \DateTimeImmutable $now): static
    {
        $this->snapshotId = $snapshotId;
        $this->missingCount = $missingCount;
        $this->syncedAt = $now;
        $this->error = null;

        return $this;
    }

    public function markFailed(string $error): static
    {
        $this->error = mb_substr($error, 0, 255);

        return $this;
    }
}
