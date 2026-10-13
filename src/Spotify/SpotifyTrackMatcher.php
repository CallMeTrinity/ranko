<?php

namespace App\Spotify;

use App\Catalog\TitleMatcher;
use App\Entity\SpotifyTrackMatch;
use App\Entity\Track;
use App\Repository\SpotifyTrackMatchRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Retrouve nos titres chez Spotify. La correspondance est gardée en base
 * (SpotifyTrackMatch) : seule la première synchro d'un titre coûte une recherche.
 */
final class SpotifyTrackMatcher
{
    /** Un titre absent de Spotify peut y arriver : on recherche de nouveau au bout d'une semaine. */
    private const string NO_MATCH_TTL = '-7 days';

    public function __construct(
        private readonly SpotifyApi $api,
        private readonly SpotifyTrackMatchRepository $matches,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Persiste la correspondance sans flush : l'appelant flush après le lot.
     *
     * @return string|null URI Spotify, null si Spotify n'a pas ce titre
     *
     * @throws SpotifyApiException
     */
    public function uriFor(Track $track, string $accessToken): ?string
    {
        $now = $this->clock->now();
        $match = $this->matches->findOneByTrack($track);

        if (null !== $match && (null !== $match->getUri() || $match->getCheckedAt() > $now->modify(self::NO_MATCH_TTL))) {
            return $match->getUri();
        }

        $uri = $this->search($track, $accessToken);

        if (null === $match) {
            $this->entityManager->persist(new SpotifyTrackMatch($track, $uri, $now));
        } else {
            $match->update($uri, $now);
        }

        return $uri;
    }

    /**
     * L'ISRC désigne l'enregistrement exact : on le cherche d'abord. Sans ISRC, ou si
     * Spotify ne le connaît pas, recherche texte comme pour Deezer (DeezerPreviewFinder) :
     * avec le nom de l'album, puis sans. Seuls les résultats du même artiste comptent :
     * mieux vaut un titre absent de la playlist que la reprise d'un autre.
     */
    private function search(Track $track, string $accessToken): ?string
    {
        $album = $track->getAlbum();
        $artist = $album->getArtist()->getName();

        if (null !== $track->getIsrc()) {
            foreach ($this->api->searchTracks($accessToken, 'isrc:'.$track->getIsrc()) as $result) {
                if (null !== $uri = $this->uriIfByArtist($result, $artist)) {
                    return $uri;
                }
            }
        }

        $title = 'track:'.TitleMatcher::searchable($track->getName());
        foreach ([[$title, 'artist:'.$artist, 'album:'.TitleMatcher::searchable($album->getName())], [$title, 'artist:'.$artist]] as $terms) {
            $best = null;
            $bestScore = \PHP_INT_MAX;

            foreach ($this->api->searchTracks($accessToken, implode(' ', $terms)) as $result) {
                $uri = $this->uriIfByArtist($result, $artist);
                if (null === $uri) {
                    continue;
                }

                // Spotify suffixe les rééditions : "Airbag - Remastered 2017".
                $name = (string) ($result['name'] ?? '');
                $score = TitleMatcher::titleScore([$name, (string) preg_replace('/ - [^-]+$/', '', $name)], $track->getName());
                if (null === $score) {
                    continue;
                }

                $score += TitleMatcher::same((string) ($result['album']['name'] ?? ''), $album->getName()) ? 0 : 1;
                if ($score < $bestScore) {
                    [$best, $bestScore] = [$uri, $score];
                }
            }

            if (null !== $best) {
                return $best;
            }
        }

        return null;
    }

    /**
     * URI du résultat s'il est de cet artiste. Spotify liste tous les artistes d'un titre
     * (featurings) : il suffit que le nôtre y soit.
     *
     * @param array<string, mixed> $result
     */
    private function uriIfByArtist(array $result, string $artist): ?string
    {
        $uri = $result['uri'] ?? null;
        if (!\is_string($uri)) {
            return null;
        }

        foreach ($result['artists'] ?? [] as $candidate) {
            if (TitleMatcher::same((string) ($candidate['name'] ?? ''), $artist)) {
                return $uri;
            }
        }

        return null;
    }
}
