<?php

namespace App\Repository;

use App\Entity\Album;
use App\Entity\Artist;
use App\Entity\CatalogItem;
use App\Entity\Track;
use App\Enum\CatalogItemKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CatalogItem>
 */
class CatalogItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CatalogItem::class);
    }

    /**
     * Éléments visibles d'un type dans un périmètre, dans l'ordre du catalogue
     * (discographie chronologique, ordre des pistes). Un élément est invisible si
     * lui-même ou l'un de ses parents est masqué.
     *
     * @return list<CatalogItem>
     */
    public function findRankable(CatalogItemKind $kind, ?CatalogItem $scope = null): array
    {
        return match ($kind) {
            CatalogItemKind::Artist => $this->findRankableArtists(),
            CatalogItemKind::Album => $this->findRankableAlbums($scope instanceof Artist ? $scope : null),
            CatalogItemKind::Track => $this->findRankableTracks($scope instanceof Artist || $scope instanceof Album ? $scope : null),
        };
    }

    /**
     * @return list<Artist>
     */
    private function findRankableArtists(): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('artist')->from(Artist::class, 'artist')
            ->andWhere('artist.hidden = false')
            ->orderBy('artist.name')
            ->getQuery()->getResult();
    }

    /**
     * @return list<Album>
     */
    private function findRankableAlbums(?Artist $artist): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('album')->from(Album::class, 'album')
            ->join('album.artist', 'artist')
            ->andWhere('album.hidden = false')
            ->andWhere('artist.hidden = false')
            ->orderBy('album.releaseDate')->addOrderBy('album.name');

        if (null !== $artist) {
            $qb->andWhere('artist = :artist')->setParameter('artist', $artist);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return list<Track>
     */
    private function findRankableTracks(Artist|Album|null $scope): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('track', 'album')->from(Track::class, 'track')
            ->join('track.album', 'album')
            ->join('album.artist', 'artist')
            ->andWhere('track.hidden = false')
            ->andWhere('album.hidden = false')
            ->andWhere('artist.hidden = false')
            ->orderBy('album.releaseDate')->addOrderBy('album.id')
            ->addOrderBy('track.discNumber')->addOrderBy('track.position');

        if ($scope instanceof Artist) {
            $qb->andWhere('artist = :scope')->setParameter('scope', $scope);
        } elseif ($scope instanceof Album) {
            $qb->andWhere('album = :scope')->setParameter('scope', $scope);
        }

        return $qb->getQuery()->getResult();
    }
}
