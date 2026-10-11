<?php

namespace App\Repository;

use App\Entity\Album;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Album>
 */
class AlbumRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Album::class);
    }

    /**
     * Albums dont la pochette n'est pas encore copiée en local.
     *
     * @return list<Album>
     */
    public function findWithRemoteCoverOnly(): array
    {
        return $this->createQueryBuilder('album')
            ->where('album.coverUrl IS NOT NULL')
            ->andWhere('album.coverPath IS NULL')
            ->getQuery()
            ->getResult();
    }
}
