<?php

namespace App\Repository;

use App\Entity\Ranking;
use App\Entity\SpotifyPlaylist;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SpotifyPlaylist>
 */
class SpotifyPlaylistRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SpotifyPlaylist::class);
    }

    public function findOneByRanking(Ranking $ranking): ?SpotifyPlaylist
    {
        return $this->findOneBy(['ranking' => $ranking]);
    }
}
