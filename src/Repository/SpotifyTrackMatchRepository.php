<?php

namespace App\Repository;

use App\Entity\SpotifyTrackMatch;
use App\Entity\Track;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SpotifyTrackMatch>
 */
class SpotifyTrackMatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SpotifyTrackMatch::class);
    }

    public function findOneByTrack(Track $track): ?SpotifyTrackMatch
    {
        return $this->findOneBy(['track' => $track]);
    }
}
