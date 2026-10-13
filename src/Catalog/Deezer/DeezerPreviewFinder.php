<?php

namespace App\Catalog\Deezer;

use App\Catalog\TitleMatcher;
use App\Entity\Track;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Extrait audio de 30 s d'un titre, via l'API publique de Deezer (https://developers.deezer.com/api).
 *
 * MusicBrainz ne fournit pas d'audio. On retrouve le titre chez Deezer par une recherche
 * texte, puis on demande son extrait au moment de l'écoute : les URLs d'extrait sont
 * signées et expirent au bout d'environ 15 minutes, on ne peut donc pas les stocker.
 *
 * La correspondance (notre titre → id Deezer) est gardée en cache, pas en ExternalReference :
 * deux de nos titres peuvent tomber sur le même titre Deezer (édition deluxe, réédition),
 * ce que la contrainte d'unicité des références refuserait.
 */
final class DeezerPreviewFinder
{
    private const int MATCH_TTL = 30 * 86400;
    private const int NO_MATCH_TTL = 86400;
    /** Marge sous l'expiration de l'URL signée, pour qu'elle reste valide pendant l'écoute. */
    private const int PREVIEW_TTL = 600;

    public function __construct(
        #[Target('deezer.client')]
        private readonly HttpClientInterface $deezer,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @return string|null URL de l'extrait, null si Deezer n'a pas ce titre ou ne répond pas
     */
    public function findPreviewUrl(Track $track): ?string
    {
        try {
            $deezerId = $this->cache->get('deezer_track_'.$track->getId(), function (ItemInterface $item) use ($track): int {
                $deezerId = $this->search($track);
                $item->expiresAfter(null === $deezerId ? self::NO_MATCH_TTL : self::MATCH_TTL);

                return $deezerId ?? 0;
            });

            if (0 === $deezerId) {
                return null;
            }

            return $this->cache->get('deezer_preview_'.$deezerId, function (ItemInterface $item) use ($deezerId): ?string {
                $item->expiresAfter(self::PREVIEW_TTL);

                return ($this->request('track/'.$deezerId)['preview'] ?? '') ?: null;
            });
        } catch (DeezerUnavailableException) {
            // Rien n'est mis en cache : on retentera à la prochaine écoute.
            return null;
        }
    }

    /**
     * Cherche d'abord avec le nom de l'album, qui départage les rééditions et les reprises,
     * puis sans. Seuls les résultats du même artiste sont retenus : mieux vaut pas d'extrait
     * que la reprise d'un autre.
     *
     * Un titre identique passe avant un titre identique sans ses parenthèses
     * (TitleMatcher::titleScore()) ; à égalité, le résultat du même album l'emporte.
     */
    private function search(Track $track): ?int
    {
        $album = $track->getAlbum();
        $artist = $album->getArtist()->getName();

        $title = TitleMatcher::searchable($track->getName());

        foreach ([[$artist, $title, TitleMatcher::searchable($album->getName())], [$artist, $title]] as $terms) {
            $best = null;
            $bestScore = \PHP_INT_MAX;

            foreach ($this->request('search', ['q' => implode(' ', $terms), 'limit' => 10])['data'] ?? [] as $result) {
                if (!TitleMatcher::same($result['artist']['name'] ?? '', $artist)) {
                    continue;
                }

                $score = TitleMatcher::titleScore([$result['title'] ?? '', $result['title_short'] ?? ''], $track->getName());
                if (null === $score) {
                    continue;
                }

                $score += TitleMatcher::same($result['album']['title'] ?? '', $album->getName()) ? 0 : 1;
                if ($score < $bestScore) {
                    [$best, $bestScore] = [$result['id'], $score];
                }
            }

            if (null !== $best) {
                return $best;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     *
     * @throws DeezerUnavailableException
     */
    private function request(string $path, array $query = []): array
    {
        try {
            $data = $this->deezer->request('GET', $path, ['query' => $query])->toArray();
        } catch (HttpClientExceptionInterface $exception) {
            throw new DeezerUnavailableException($exception->getMessage(), previous: $exception);
        }

        // Deezer répond 200 même en erreur (quota dépassé, id inconnu), avec un objet "error".
        if (isset($data['error'])) {
            throw new DeezerUnavailableException($data['error']['message'] ?? 'Erreur Deezer');
        }

        return $data;
    }
}
