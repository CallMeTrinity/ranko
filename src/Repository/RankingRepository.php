<?php

namespace App\Repository;

use App\Entity\CatalogItem;
use App\Entity\Ranking;
use App\Entity\RankingEntry;
use App\Entity\User;
use App\Enum\CatalogItemKind;
use App\Enum\Visibility;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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
        return $this->createQueryBuilder('ranking')
            ->addSelect('scope')
            ->leftJoin('ranking.scope', 'scope')
            ->andWhere('ranking.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('ranking.updatedAt', 'DESC')
            ->getQuery()->getResult();
    }

    /**
     * Derniers classements publics non vides, du plus récemment modifié au plus ancien.
     *
     * @return list<Ranking>
     */
    public function findLatestPublic(?CatalogItemKind $kind = null, int $limit = 30): array
    {
        $qb = $this->publicQueryBuilder()->setMaxResults($limit);
        if (null !== $kind) {
            $qb->andWhere('ranking.itemKind = :kind')->setParameter('kind', $kind);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return list<Ranking>
     */
    public function findPublicByOwner(User $owner): array
    {
        return $this->publicQueryBuilder()
            ->andWhere('ranking.owner = :owner')
            ->setParameter('owner', $owner)
            ->getQuery()->getResult();
    }

    /**
     * Classements publics de la communauté portant sur cet artiste ou cet album.
     *
     * @return list<Ranking>
     */
    public function findPublicByScope(CatalogItem $scope, int $limit = 10): array
    {
        return $this->publicQueryBuilder()
            ->andWhere('ranking.scope = :scope')
            ->setParameter('scope', $scope)
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * Classements de l'utilisateur comparables à celui-ci (même type d'élément), celui
     * de même périmètre en premier.
     *
     * @return list<Ranking>
     */
    public function findComparable(User $user, Ranking $ranking): array
    {
        $rankings = $this->createQueryBuilder('ranking')
            ->andWhere('ranking.owner = :owner')
            ->andWhere('ranking.itemKind = :kind')
            ->andWhere('ranking != :ranking')
            ->setParameter('owner', $user)
            ->setParameter('kind', $ranking->getItemKind())
            ->setParameter('ranking', $ranking)
            ->orderBy('ranking.updatedAt', 'DESC')
            ->getQuery()->getResult();

        usort($rankings, static fn (Ranking $a, Ranking $b): int => ($b->getScope() === $ranking->getScope()) <=> ($a->getScope() === $ranking->getScope()));

        return $rankings;
    }

    /**
     * Les premiers éléments de chaque classement, en une seule requête pour toute la liste.
     *
     * @param list<Ranking> $rankings
     *
     * @return array<int, list<CatalogItem>> indexé par id de classement
     */
    public function findTopItems(array $rankings, int $limit = 3): array
    {
        if ([] === $rankings) {
            return [];
        }

        /** @var list<RankingEntry> $entries */
        $entries = $this->getEntityManager()->createQueryBuilder()
            ->select('entry', 'item')
            ->from(RankingEntry::class, 'entry')
            ->join('entry.item', 'item')
            ->andWhere('entry.ranking IN (:rankings)')
            ->andWhere('entry.position <= :limit')
            ->setParameter('rankings', $rankings)
            ->setParameter('limit', $limit)
            ->orderBy('entry.position')
            ->getQuery()->getResult();

        $tops = [];
        foreach ($entries as $entry) {
            $tops[(int) $entry->getRanking()->getId()][] = $entry->getItem();
        }

        return $tops;
    }

    private function publicQueryBuilder(): QueryBuilder
    {
        // Fetch join du périmètre : le titre l'affiche, et une association vers la racine
        // d'héritage serait sinon chargée par une requête par classement.
        return $this->createQueryBuilder('ranking')
            ->addSelect('owner', 'scope')
            ->join('ranking.owner', 'owner')
            ->leftJoin('ranking.scope', 'scope')
            ->andWhere('ranking.visibility = :public')
            ->andWhere('SIZE(ranking.entries) > 0')
            ->setParameter('public', Visibility::Public)
            ->orderBy('ranking.updatedAt', 'DESC');
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
