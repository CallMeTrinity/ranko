<?php

namespace App\Repository;

use App\Entity\Duel;
use App\Entity\Ranking;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Duel>
 */
class DuelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Duel::class);
    }

    public function findOneByRanking(Ranking $ranking): ?Duel
    {
        return $this->findOneBy(['ranking' => $ranking]);
    }
}
