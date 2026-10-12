<?php

namespace App\Repository;

use App\Entity\Album;
use App\Entity\Artist;
use App\Entity\CatalogItem;
use App\Entity\Track;
use App\Enum\CatalogItemKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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
        return $this->rankableQueryBuilder($kind, $scope)->getQuery()->getResult();
    }

    /**
     * Comme findRankable(), filtré par une recherche sur le nom de l'élément ou de ses
     * parents ("radiohead" trouve aussi ses albums et ses titres), sans les éléments
     * exclus et limité en nombre.
     *
     * @param list<int> $excludedIds
     *
     * @return list<CatalogItem>
     */
    public function searchRankable(CatalogItemKind $kind, ?CatalogItem $scope, string $search, array $excludedIds, int $limit): array
    {
        $qb = $this->rankableQueryBuilder($kind, $scope)->setMaxResults($limit);
        $alias = $qb->getRootAliases()[0];

        if ('' !== $search) {
            // Les alias existent selon le type : artist toujours, album pour un album ou un titre.
            $fields = match ($kind) {
                CatalogItemKind::Artist => ['artist.name'],
                CatalogItemKind::Album => ['album.name', 'artist.name'],
                CatalogItemKind::Track => ['track.name', 'album.name', 'artist.name'],
            };
            $qb->andWhere($qb->expr()->orX(...array_map(static fn (string $field): string => $field.' LIKE :search', $fields)))
                ->setParameter('search', '%'.addcslashes($search, '%_\\').'%');
        }

        if ([] !== $excludedIds) {
            $qb->andWhere($alias.'.id NOT IN (:excluded)')->setParameter('excluded', $excludedIds);
        }

        return $qb->getQuery()->getResult();
    }

    private function rankableQueryBuilder(CatalogItemKind $kind, ?CatalogItem $scope): QueryBuilder
    {
        return match ($kind) {
            CatalogItemKind::Artist => $this->rankableArtists(),
            CatalogItemKind::Album => $this->rankableAlbums($scope instanceof Artist ? $scope : null),
            CatalogItemKind::Track => $this->rankableTracks($scope instanceof Artist || $scope instanceof Album ? $scope : null),
        };
    }

    private function rankableArtists(): QueryBuilder
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('artist')->from(Artist::class, 'artist')
            ->andWhere('artist.hidden = false')
            ->orderBy('artist.name');
    }

    private function rankableAlbums(?Artist $artist): QueryBuilder
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

        return $qb;
    }

    private function rankableTracks(Artist|Album|null $scope): QueryBuilder
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

        return $qb;
    }
}
