<?php

namespace App\Repository;

use App\Entity\SpotifyAccount;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SpotifyAccount>
 */
class SpotifyAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SpotifyAccount::class);
    }

    public function findOneByUser(User $user): ?SpotifyAccount
    {
        return $this->findOneBy(['user' => $user]);
    }
}
