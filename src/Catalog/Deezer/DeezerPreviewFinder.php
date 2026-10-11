<?php

namespace App\Catalog\Deezer;

use App\Entity\Track;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function Symfony\Component\String\u;

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
     * Un titre identique passe avant un titre identique une fois les parenthèses retirées
     * ("Stop Whispering (US version)" chez MusicBrainz, "Stop Whispering" chez Deezer) ;
     * à égalité, le résultat du même album l'emporte.
     */
    private function search(Track $track): ?int
    {
        $album = $track->getAlbum();
        $artist = $album->getArtist()->getName();

        $title = $this->searchable($track->getName());

        foreach ([[$artist, $title, $this->searchable($album->getName())], [$artist, $title]] as $terms) {
            $best = null;
            $bestScore = \PHP_INT_MAX;

            foreach ($this->request('search', ['q' => implode(' ', $terms), 'limit' => 10])['data'] ?? [] as $result) {
                if (!$this->same($result['artist']['name'] ?? '', $artist)) {
                    continue;
                }

                $titles = [$result['title'] ?? '', $result['title_short'] ?? ''];
                $score = match (true) {
                    $this->sameTitle($titles, $track->getName(), static fn (string $title): string => $title) => 0,
                    $this->sameTitle($titles, $track->getName(), $this->withoutParentheses(...)) => 2,
                    default => null,
                };

                if (null === $score) {
                    continue;
                }

                $score += $this->same($result['album']['title'] ?? '', $album->getName()) ? 0 : 1;
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
     * @param list<string>              $candidates titre et titre court Deezer
     * @param callable(string): string $simplify
     */
    private function sameTitle(array $candidates, string $title, callable $simplify): bool
    {
        foreach ($candidates as $candidate) {
            if ($this->same($simplify($candidate), $simplify($title))) {
                return true;
            }
        }

        return false;
    }

    private function withoutParentheses(string $title): string
    {
        return (string) preg_replace('/\s*[(\[][^)\]]*[)\]]/', '', $title);
    }

    /**
     * La recherche Deezer ne renvoie rien dès que la requête contient une parenthèse.
     * On retire donc la partie entre parenthèses, sauf si c'est tout le titre ("(Nice Dream)").
     */
    private function searchable(string $title): string
    {
        $withoutParentheses = trim($this->withoutParentheses($title));

        return '' !== $withoutParentheses ? $withoutParentheses : trim(strtr($title, '()[]', '    '));
    }

    /**
     * Compare sans tenir compte de la casse, des accents ni de la ponctuation :
     * MusicBrainz écrit "’" là où Deezer écrit "'".
     */
    private function same(string $a, string $b): bool
    {
        $normalize = static fn (string $value): string => u($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '')->toString();

        return '' !== $normalize($a) && $normalize($a) === $normalize($b);
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
