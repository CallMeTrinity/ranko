<?php

namespace App\Spotify;

use App\Entity\Ranking;
use App\Entity\SpotifyPlaylist;
use App\Message\SyncRankingToSpotify;
use App\Repository\SpotifyPlaylistRepository;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Remet la playlist Spotify à jour quand son classement change : réordonner, ajouter,
 * retirer, terminer un duel. Tout passe par Ranking::$updatedAt, d'où un listener
 * Doctrine plutôt qu'un appel dans chaque service qui modifie un classement.
 *
 * Seulement pour une playlist déjà créée : l'utilisateur l'a demandée une première fois.
 * Les modifications qui arrivent pendant qu'un message attend dans la file s'y rattachent
 * (SpotifyPlaylist::$queued) : vingt déplacements pendant un duel font une seule synchro.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class SpotifyAutoSyncListener
{
    /** @var list<int> classements dont la synchro part après le flush */
    private array $pending = [];

    public function __construct(
        private readonly SpotifyPlaylistRepository $playlists,
        private readonly MessageBusInterface $bus,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Le changement de la playlist (requestedAt, queued) part dans le même flush que celui
     * du classement : c'est ce que permet onFlush, en recalculant son changeset.
     */
    public function onFlush(OnFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();
        $this->pending = [];

        foreach ($unitOfWork->getScheduledEntityUpdates() as $ranking) {
            if (!$ranking instanceof Ranking || !\array_key_exists('updatedAt', $unitOfWork->getEntityChangeSet($ranking))) {
                continue;
            }

            $playlist = $this->playlists->findOneByRanking($ranking);
            if (null === $playlist || null === $playlist->getPlaylistId() || !$ranking->getOwner()->hasRole(SpotifyAccess::ROLE)) {
                continue;
            }

            if ($playlist->request($this->clock->now())) {
                $this->pending[] = (int) $ranking->getId();
            }
            $unitOfWork->recomputeSingleEntityChangeSet($entityManager->getClassMetadata(SpotifyPlaylist::class), $playlist);
        }
    }

    /**
     * Après le flush seulement : un message envoyé avant pourrait être traité alors que
     * la transaction n'est pas encore validée.
     */
    public function postFlush(PostFlushEventArgs $args): void
    {
        $rankingIds = $this->pending;
        $this->pending = [];

        foreach ($rankingIds as $rankingId) {
            $this->bus->dispatch(new SyncRankingToSpotify($rankingId));
        }
    }
}
