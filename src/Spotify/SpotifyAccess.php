<?php

namespace App\Spotify;

use App\Entity\User;
use App\Repository\SpotifyAccountRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Qui a droit à la synchro Spotify. En Development Mode, Spotify n'accepte qu'une poignée
 * de comptes, ajoutés à la main dans le dashboard (User Management) : l'admin attribue
 * donc les places une par une. Aucune API ne permet de remplir cette liste côté Spotify.
 */
final class SpotifyAccess
{
    public const string ROLE = 'ROLE_SPOTIFY';

    public function __construct(
        private readonly UserRepository $users,
        private readonly SpotifyAccountRepository $accounts,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%env(int:SPOTIFY_MAX_USERS)%')]
        private readonly int $maxUsers,
    ) {
    }

    public function usedSeats(): int
    {
        return $this->users->countWithRole(self::ROLE);
    }

    public function maxUsers(): int
    {
        return $this->maxUsers;
    }

    public function hasFreeSeat(): bool
    {
        return $this->usedSeats() < $this->maxUsers;
    }

    /**
     * @throws \DomainException si toutes les places sont prises
     */
    public function grant(User $user): void
    {
        if ($user->hasRole(self::ROLE)) {
            return;
        }

        if (!$this->hasFreeSeat()) {
            throw new \DomainException(sprintf('Les %d places Spotify sont prises : retire l\'accès à quelqu\'un d\'abord.', $this->maxUsers));
        }

        $user->addRole(self::ROLE);
        $this->entityManager->flush();
    }

    /**
     * Supprime aussi ses tokens : la place se libère pour quelqu'un d'autre. Ses playlists
     * restent dans son compte Spotify, et leur lien avec ses classements est gardé pour
     * le jour où l'accès revient.
     */
    public function revoke(User $user): void
    {
        $user->removeRole(self::ROLE);

        $account = $this->accounts->findOneByUser($user);
        if (null !== $account) {
            $this->entityManager->remove($account);
        }

        $this->entityManager->flush();
    }
}
