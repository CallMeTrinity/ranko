<?php

namespace App\Spotify;

use App\Entity\Ranking;
use App\Entity\SpotifyAccount;
use App\Entity\SpotifyPlaylist;
use App\Entity\Track;
use App\Enum\CatalogItemKind;
use App\Message\SyncRankingToSpotify;
use App\Repository\RankingRepository;
use App\Repository\SpotifyAccountRepository;
use App\Repository\SpotifyPlaylistRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Copie un classement de titres dans une playlist Spotify de son propriétaire. Synchro
 * à sens unique : chaque passage réécrit toute la playlist dans l'ordre du classement,
 * une modification faite dans Spotify est donc écrasée.
 */
final class SpotifyPlaylistSync
{
    public function __construct(
        private readonly SpotifyConnector $connector,
        private readonly SpotifyTrackMatcher $matcher,
        private readonly SpotifyApi $api,
        private readonly SpotifyAccountRepository $accounts,
        private readonly SpotifyPlaylistRepository $playlists,
        private readonly RankingRepository $rankings,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(Ranking $ranking): bool
    {
        return CatalogItemKind::Track === $ranking->getItemKind();
    }

    /**
     * Demande manuelle. La recherche des titres peut prendre une requête par titre : la
     * synchro passe par la file Messenger plutôt que de faire attendre la page.
     */
    public function request(Ranking $ranking): SpotifyPlaylist
    {
        if (!$this->supports($ranking)) {
            throw new \InvalidArgumentException('Seul un classement de titres devient une playlist.');
        }

        $playlist = $this->playlists->findOneByRanking($ranking);
        if (null === $playlist) {
            $playlist = new SpotifyPlaylist($ranking);
            $this->entityManager->persist($playlist);
        }

        $playlist->request($this->clock->now(), force: true);
        $this->entityManager->flush();

        $this->bus->dispatch(new SyncRankingToSpotify((int) $ranking->getId()));

        return $playlist;
    }

    /**
     * Un échec définitif (accès révoqué, compte pas autorisé) est noté sur la playlist,
     * pour que l'utilisateur le voie. Un échec passager est noté aussi, puis relancé :
     * Messenger rejouera le message.
     *
     * @throws SpotifyApiException si l'échec est passager (SpotifyApiException::isRetryable())
     */
    public function sync(int $rankingId): void
    {
        $playlist = $this->playlists->findOneBy(['ranking' => $rankingId]);
        if (null === $playlist) {
            return;
        }

        // Avant de lire le classement : une modification arrivée ensuite enverra son propre
        // message au lieu de se rattacher à celui-ci.
        $playlist->start();
        $readAt = $this->clock->now();
        $this->entityManager->flush();

        $ranking = $this->rankings->findWithItems($rankingId);
        if (null === $ranking) {
            return;
        }

        $account = $this->accounts->findOneByUser($ranking->getOwner());
        if (null === $account || !$ranking->getOwner()->hasRole(SpotifyAccess::ROLE)) {
            $playlist->markFailed('Aucun compte Spotify connecté.');
            $this->entityManager->flush();

            return;
        }

        try {
            $this->push($ranking, $playlist, $account, $readAt);
        } catch (SpotifyApiException $exception) {
            // Le message brut de Spotify : celui affiché à l'utilisateur le simplifie.
            $this->logger->warning('Synchro Spotify du classement {ranking} : {message}', ['ranking' => $ranking->getId(), 'message' => $exception->getMessage(), 'exception' => $exception]);
            $playlist->markFailed($this->explain($exception));
            // Garde aussi les titres déjà trouvés : le prochain essai ne les recherchera pas.
            $this->entityManager->flush();

            if ($exception->isRetryable()) {
                throw $exception;
            }
        }
    }

    private function push(Ranking $ranking, SpotifyPlaylist $playlist, SpotifyAccount $account, \DateTimeImmutable $readAt): void
    {
        $token = $this->connector->accessToken($account);

        $uris = [];
        $missing = 0;
        foreach ($ranking->getItems() as $item) {
            if (!$item instanceof Track) {
                continue;
            }

            $uri = $this->matcher->uriFor($item, $token);
            null === $uri ? ++$missing : $uris[] = $uri;
        }
        $this->entityManager->flush();

        // Un même titre Spotify peut correspondre à deux des nôtres (réédition) : la
        // playlist l'aurait en double.
        $uris = array_values(array_unique($uris));

        // Après une reconnexion avec un autre compte, l'ancienne playlist ne lui appartient pas.
        if (null === $playlist->getPlaylistId() || $playlist->getSpotifyUserId() !== $account->getSpotifyUserId()) {
            $this->create($ranking, $playlist, $account, $token);
        }

        try {
            $snapshotId = $this->api->replacePlaylistItems($token, (string) $playlist->getPlaylistId(), $uris);
        } catch (SpotifyApiException $exception) {
            if (404 !== $exception->statusCode) {
                throw $exception;
            }

            $this->create($ranking, $playlist, $account, $token);
            $snapshotId = $this->api->replacePlaylistItems($token, (string) $playlist->getPlaylistId(), $uris);
        }

        $playlist->markSynced($snapshotId, $missing, $readAt);
        $this->entityManager->flush();
    }

    private function create(Ranking $ranking, SpotifyPlaylist $playlist, SpotifyAccount $account, string $token): void
    {
        $created = $this->api->createPlaylist($token, $ranking->getTitle(), sprintf(
            'Classement Ranko de %s : %s',
            $ranking->getOwner()->getUsername(),
            $this->urlGenerator->generate('app_ranking_show', ['id' => $ranking->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
        ));

        $playlist->attach($created['id'], $account->getSpotifyUserId(), $created['url']);
        // Tout de suite : si la suite échoue, le prochain essai remplit cette playlist au lieu d'en créer une autre.
        $this->entityManager->flush();
    }

    private function explain(SpotifyApiException $exception): string
    {
        return match (true) {
            $exception->isRetryable() => 'Spotify ne répond pas pour l\'instant : nouvel essai automatique dans quelques minutes.',
            400 === $exception->statusCode, 401 === $exception->statusCode, $exception->isMissingScope() => 'Spotify a refusé l\'accès : reconnecte ton compte Spotify depuis ton profil.',
            403 === $exception->statusCode => 'Ce compte Spotify n\'est pas autorisé sur Ranko : demande à l\'admin de vérifier l\'email de ton compte Spotify.',
            default => 'Spotify a refusé la synchro ('.$exception->statusCode.').',
        };
    }
}
