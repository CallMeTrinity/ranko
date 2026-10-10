<?php

namespace App\Repository;

use App\Entity\CatalogItem;
use App\Entity\Ranking;
use App\Entity\User;
use App\Enum\CatalogItemKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Ranking>
 */
class RankingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ranking::class);
    }

    public function findOneByOwnerAndTarget(User $owner, CatalogItemKind $kind, ?CatalogItem $scope): ?Ranking
    {
        // findOneBy(['scope' => null]) génère bien "IS NULL" : un seul chemin pour les deux cas.
        return $this->findOneBy(['owner' => $owner, 'itemKind' => $kind, 'scope' => $scope]);
    }

    /**
     * @return list<Ranking>
     */
    public function findByOwner(User $owner): array
    {
        return $this->findBy(['owner' => $owner], ['updatedAt' => 'DESC']);
    }

    /**
     * Charge le classement avec ses entrées et leurs éléments en une requête. Sans ce
     * fetch join, chaque entrée déclencherait sa requête : une association vers
     * CatalogItem (racine d'héritage) ne peut pas être lazy.
     */
    public function findWithItems(int $id): ?Ranking
    {
        return $this->createQueryBuilder('ranking')
            ->addSelect('entry', 'item')
            ->leftJoin('ranking.entries', 'entry')
            ->leftJoin('entry.item', 'item')
            ->andWhere('ranking.id = :id')
            ->setParameter('id', $id)
            ->orderBy('entry.position')
            ->getQuery()
            ->getOneOrNullResult();
    }
}
