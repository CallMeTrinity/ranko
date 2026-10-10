<?php

namespace App\Repository;

use App\Entity\Artist;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Artist>
 */
class ArtistRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Artist::class);
    }

    /**
     * @return list<Artist>
     */
    public function findVisible(string $search = ''): array
    {
        $qb = $this->createQueryBuilder('artist')
            ->andWhere('artist.hidden = false')
            ->orderBy('artist.name');

        if ('' !== $search) {
            $qb->andWhere('artist.name LIKE :search')
                ->setParameter('search', '%'.addcslashes($search, '%_\\').'%');
        }

        return $qb->getQuery()->getResult();
    }
}
