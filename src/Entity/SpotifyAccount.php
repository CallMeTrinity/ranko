<?php

namespace App\Entity;

use App\Repository\SpotifyAccountRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Compte Spotify connecté par OAuth. Le refresh token permet d'agir pour l'utilisateur
 * sans qu'il se reconnecte : il ne donne accès qu'aux scopes demandés (modifier ses
 * playlists), mais reste un secret.
 */
#[ORM\Entity(repositoryClass: SpotifyAccountRepository::class)]
class SpotifyAccount
{
    /** Marge avant l'expiration : un token qui expire pendant la synchro ferait échouer la requête. */
    private const int EXPIRY_MARGIN = 60;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $connectedAt;

    public function __construct(
        #[ORM\OneToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        /** Identifiant du compte chez Spotify : une playlist appartient à ce compte-là. */
        #[ORM\Column(length: 64)]
        private string $spotifyUserId,
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $displayName,
        #[ORM\Column(type: Types::TEXT)]
        private string $accessToken,
        #[ORM\Column(type: Types::TEXT)]
        private string $refreshToken,
        #[ORM\Column]
        private \DateTimeImmutable $expiresAt,
    ) {
        $this->connectedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getSpotifyUserId(): string
    {
        return $this->spotifyUserId;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    /**
     * Nom affiché par Spotify, à défaut son identifiant.
     */
    public function getLabel(): string
    {
        return $this->displayName ?? $this->spotifyUserId;
    }

    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    public function getRefreshToken(): string
    {
        return $this->refreshToken;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getConnectedAt(): \DateTimeImmutable
    {
        return $this->connectedAt;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now->modify(sprintf('+%d seconds', self::EXPIRY_MARGIN));
    }

    /**
     * Spotify ne renvoie pas toujours de nouveau refresh token : on garde alors l'ancien.
     */
    public function updateTokens(string $accessToken, ?string $refreshToken, \DateTimeImmutable $expiresAt): static
    {
        $this->accessToken = $accessToken;
        $this->refreshToken = $refreshToken ?? $this->refreshToken;
        $this->expiresAt = $expiresAt;

        return $this;
    }

    /**
     * Reconnexion, éventuellement avec un autre compte Spotify.
     */
    public function reconnect(string $spotifyUserId, ?string $displayName, string $accessToken, string $refreshToken, \DateTimeImmutable $expiresAt): static
    {
        $this->spotifyUserId = $spotifyUserId;
        $this->displayName = $displayName;
        $this->connectedAt = new \DateTimeImmutable();

        return $this->updateTokens($accessToken, $refreshToken, $expiresAt);
    }
}
