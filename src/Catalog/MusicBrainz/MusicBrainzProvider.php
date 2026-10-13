<?php

namespace App\Catalog\MusicBrainz;

use App\Catalog\CatalogProviderInterface;
use App\Catalog\Data\AlbumData;
use App\Catalog\Data\ArtistData;
use App\Catalog\Data\TrackData;
use App\Catalog\Data\Tracklist;
use App\Catalog\Exception\ItemNotFoundException;
use App\Enum\AlbumType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function Symfony\Component\String\u;

/**
 * API MusicBrainz (https://musicbrainz.org/doc/MusicBrainz_API).
 *
 * Le rate limit (1 requête/s) et le User-Agent sont portés par le client scopé
 * "musicbrainz.client" (config/packages/catalog.yaml). Les 503 sont rejoués par
 * Messenger, pas par le client HTTP (voir la config).
 */
#[AsAlias(CatalogProviderInterface::class)]
final class MusicBrainzProvider implements CatalogProviderInterface
{
    public const string NAME = 'musicbrainz';

    private const int PAGE_SIZE = 100;

    public function __construct(
        #[Target('musicbrainz.client')]
        private readonly HttpClientInterface $musicbrainz,
        #[Target('cover_art_archive.client')]
        private readonly HttpClientInterface $coverArtArchive,
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function searchArtists(string $query, int $limit = 10): array
    {
        $data = $this->get('artist', ['query' => $query, 'limit' => $limit]);

        return array_values(array_map($this->toArtistData(...), $data['artists'] ?? []));
    }

    public function fetchArtist(string $externalId): ArtistData
    {
        return $this->toArtistData($this->get('artist/'.$externalId, [], 'artist'));
    }

    public function fetchAlbums(string $artistExternalId): array
    {
        $albums = [];
        $offset = 0;

        do {
            $page = $this->get('release-group', [
                'artist' => $artistExternalId,
                // Les singles portent les faces B. Ils arrivent masqués (AlbumData::isMainRelease()).
                'type' => 'album|ep|single',
                // Sorties officielles seulement : exclut les centaines de bootlegs.
                'release-group-status' => 'website-default',
                'limit' => self::PAGE_SIZE,
                'offset' => $offset,
            ], 'artist', $artistExternalId);

            foreach ($page['release-groups'] ?? [] as $group) {
                $albums[] = new AlbumData(
                    externalId: $group['id'],
                    title: $group['title'],
                    type: $this->toAlbumType($group['primary-type'] ?? null),
                    secondaryTypes: array_values($group['secondary-types'] ?? []),
                    releaseDate: ($group['first-release-date'] ?? '') ?: null,
                );
            }

            $offset += self::PAGE_SIZE;
        } while ($offset < ($page['release-group-count'] ?? 0));

        return $albums;
    }

    public function fetchTracklist(string $albumExternalId, ?string $editionId = null): Tracklist
    {
        if (null === $editionId) {
            $group = $this->get('release-group/'.$albumExternalId, ['inc' => 'releases'], 'release-group');

            if ('Single' === ($group['primary-type'] ?? null)) {
                return $this->mergeEditions($albumExternalId);
            }

            $editionId = $this->pickEdition($group['releases'] ?? []);
            if (null === $editionId) {
                return new Tracklist(null, []);
            }
        }

        // isrcs : code international d'un enregistrement, qui sert à le retrouver chez Spotify.
        $release = $this->get('release/'.$editionId, ['inc' => 'recordings+isrcs'], 'release');

        $tracks = [];
        foreach ($release['media'] ?? [] as $medium) {
            foreach ($medium['tracks'] ?? [] as $track) {
                $tracks[] = new TrackData(
                    externalId: $track['id'],
                    title: $track['title'],
                    discNumber: (int) $medium['position'],
                    position: (int) $track['position'],
                    durationMs: $track['length'] ?? $track['recording']['length'] ?? null,
                    isrc: $track['recording']['isrcs'][0] ?? null,
                );
            }
        }

        return new Tracklist($editionId, $tracks);
    }

    public function fetchCoverUrl(string $albumExternalId): ?string
    {
        try {
            $data = $this->coverArtArchive->request('GET', 'release-group/'.$albumExternalId)->toArray();
        } catch (HttpClientExceptionInterface) {
            // 404 : pas de pochette. 5xx ou réseau : les miroirs archive.org flanchent
            // parfois. Dans les deux cas la pochette est un bonus, elle ne doit pas faire
            // échouer l'import des titres ; l'importeur garde alors l'ancienne.
            return null;
        }

        foreach ($data['images'] ?? [] as $image) {
            if ($image['front'] ?? false) {
                // 1200 px plutôt que l'original, qui peut peser plusieurs Mo : c'est la
                // source des variantes redimensionnées, la plus grande fait 1024 px.
                return $image['thumbnails']['1200'] ?? $image['thumbnails']['500'] ?? $image['image'] ?? null;
            }
        }

        return null;
    }

    /**
     * Plus ancienne édition officielle : c'est en général la tracklist d'origine.
     *
     * @param list<array<string, mixed>> $releases
     */
    private function pickEdition(array $releases): ?string
    {
        return $this->officialByDate($releases)[0]['id'] ?? null;
    }

    /**
     * Les faces B d'un single sont réparties entre ses éditions (CD1, CD2, pressages
     * étrangers) : on garde chaque titre de chacune, une seule fois. Un titre est un doublon
     * s'il reprend un enregistrement déjà vu, ou un titre déjà vu au nom près ("Can’t"/"Can't",
     * enregistrements en double chez MusicBrainz). Les versions live ou instrumentales passent
     * donc : c'est au back-office de les masquer.
     *
     * Pas d'édition renvoyée : chaque titre garde l'id de sa première apparition, et les
     * éditions sont parcourues dans un ordre fixe pour que les réimports retombent dessus.
     */
    private function mergeEditions(string $albumExternalId): Tracklist
    {
        $releases = [];
        $offset = 0;

        do {
            $page = $this->get('release', [
                'release-group' => $albumExternalId,
                'inc' => 'recordings+isrcs',
                'limit' => self::PAGE_SIZE,
                'offset' => $offset,
            ], 'release-group', $albumExternalId);

            array_push($releases, ...$page['releases'] ?? []);
            $offset += self::PAGE_SIZE;
        } while ($offset < ($page['release-count'] ?? 0));

        $tracks = [];
        $seen = [];

        foreach ($this->officialByDate($releases) as $release) {
            foreach ($release['media'] ?? [] as $medium) {
                foreach ($medium['tracks'] ?? [] as $track) {
                    $title = u($track['title'])->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '')->toString();
                    $keys = array_filter(['recording:'.($track['recording']['id'] ?? $track['id']), '' !== $title ? 'title:'.$title : null]);
                    if ([] !== array_intersect_key($seen, array_flip($keys))) {
                        continue;
                    }
                    $seen += array_fill_keys($keys, true);

                    $tracks[] = new TrackData(
                        externalId: $track['id'],
                        title: $track['title'],
                        discNumber: 1,
                        position: \count($tracks) + 1,
                        durationMs: $track['length'] ?? $track['recording']['length'] ?? null,
                        isrc: $track['recording']['isrcs'][0] ?? null,
                    );
                }
            }
        }

        return new Tracklist(null, $tracks);
    }

    /**
     * Éditions officielles (toutes si aucune ne l'est), de la plus ancienne à la plus
     * récente. Une édition sans date passe après toutes les éditions datées ; à date égale,
     * l'id départage pour que l'ordre ne dépende pas de la réponse de l'API.
     *
     * @param list<array<string, mixed>> $releases
     *
     * @return list<array<string, mixed>>
     */
    private function officialByDate(array $releases): array
    {
        $official = array_filter($releases, static fn (array $release): bool => 'Official' === ($release['status'] ?? null));
        $candidates = [] !== $official ? array_values($official) : $releases;

        usort($candidates, static fn (array $a, array $b): int => [($a['date'] ?? '') ?: '9999', $a['id']] <=> [($b['date'] ?? '') ?: '9999', $b['id']]);

        return $candidates;
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = [], ?string $notFoundType = null, ?string $notFoundId = null): array
    {
        try {
            return $this->musicbrainz->request('GET', $path, ['query' => $query + ['fmt' => 'json']])->toArray();
        } catch (ClientExceptionInterface $exception) {
            if (null !== $notFoundType && 404 === $exception->getResponse()->getStatusCode()) {
                throw ItemNotFoundException::forId($notFoundType, $notFoundId ?? basename($path), $exception);
            }

            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $artist
     */
    private function toArtistData(array $artist): ArtistData
    {
        return new ArtistData(
            externalId: $artist['id'],
            name: $artist['name'],
            sortName: $artist['sort-name'] ?? null,
            disambiguation: ($artist['disambiguation'] ?? '') ?: null,
            country: $artist['country'] ?? null,
        );
    }

    private function toAlbumType(?string $primaryType): AlbumType
    {
        return match ($primaryType) {
            'Album' => AlbumType::Album,
            'EP' => AlbumType::Ep,
            'Single' => AlbumType::Single,
            default => AlbumType::Other,
        };
    }
}
