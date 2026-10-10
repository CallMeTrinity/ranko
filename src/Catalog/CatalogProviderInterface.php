<?php

namespace App\Catalog;

use App\Catalog\Data\AlbumData;
use App\Catalog\Data\ArtistData;
use App\Catalog\Data\Tracklist;
use App\Catalog\Exception\ItemNotFoundException;

/**
 * Source externe du catalogue musical. Les identifiants manipulés sont ceux de la
 * source, jamais nos ids internes.
 */
interface CatalogProviderInterface
{
    /**
     * Nom stocké dans ExternalReference::provider.
     */
    public function getName(): string;

    /**
     * @return list<ArtistData>
     */
    public function searchArtists(string $query, int $limit = 10): array;

    /**
     * @throws ItemNotFoundException
     */
    public function fetchArtist(string $externalId): ArtistData;

    /**
     * Albums et EP officiels de l'artiste, y compris lives et compilations.
     *
     * @return list<AlbumData>
     *
     * @throws ItemNotFoundException
     */
    public function fetchAlbums(string $artistExternalId): array;

    /**
     * Liste des titres d'une édition de l'album. Sans édition imposée, le provider
     * choisit la plus représentative ; la réutiliser ensuite garde des ids de titres stables.
     *
     * @throws ItemNotFoundException
     */
    public function fetchTracklist(string $albumExternalId, ?string $editionId = null): Tracklist;

    public function fetchCoverUrl(string $albumExternalId): ?string;
}
