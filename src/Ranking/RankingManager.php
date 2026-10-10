<?php

namespace App\Ranking;

use App\Entity\CatalogItem;
use App\Entity\Ranking;
use App\Entity\User;
use App\Enum\CatalogItemKind;
use App\Enum\Visibility;
use App\Repository\CatalogItemRepository;
use App\Repository\RankingRepository;
use Doctrine\ORM\EntityManagerInterface;

final class RankingManager
{
    public function __construct(
        private readonly RankingRepository $rankings,
        private readonly CatalogItemRepository $catalog,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Un utilisateur a au plus un classement par couple (type, périmètre). Un classement
     * avec périmètre naît rempli dans l'ordre du catalogue : on n'a plus qu'à réordonner.
     * Sans périmètre (tout le catalogue), il naît vide et se remplit élément par élément.
     */
    public function findOrCreate(User $owner, CatalogItemKind $kind, ?CatalogItem $scope = null): Ranking
    {
        $ranking = $this->rankings->findOneByOwnerAndTarget($owner, $kind, $scope);
        if (null !== $ranking) {
            return $ranking;
        }

        $ranking = new Ranking($owner, $kind, $scope);
        if (null !== $scope) {
            foreach ($this->catalog->findRankable($kind, $scope) as $item) {
                $ranking->add($item);
            }
        }

        $this->entityManager->persist($ranking);
        $this->entityManager->flush();

        return $ranking;
    }

    /**
     * Ajoute l'élément au classement global de son type (créé au besoin), en dernier.
     */
    public function addToGlobalRanking(User $owner, CatalogItem $item): Ranking
    {
        $ranking = $this->findOrCreate($owner, $item->getKind());
        $ranking->add($item);
        $this->entityManager->flush();

        return $ranking;
    }

    /**
     * Éléments du périmètre pas encore classés : nouveaux albums importés, éléments
     * rendus visibles... Toujours vide pour un classement sans périmètre.
     *
     * @return list<CatalogItem>
     */
    public function findMissingItems(Ranking $ranking): array
    {
        if (null === $ranking->getScope()) {
            return [];
        }

        return array_values(array_filter(
            $this->catalog->findRankable($ranking->getItemKind(), $ranking->getScope()),
            static fn (CatalogItem $item): bool => !$ranking->contains($item),
        ));
    }

    public function appendMissingItems(Ranking $ranking): void
    {
        foreach ($this->findMissingItems($ranking) as $item) {
            $ranking->add($item);
        }

        $this->entityManager->flush();
    }

    /**
     * @param list<int> $itemIds
     */
    public function reorder(Ranking $ranking, array $itemIds): void
    {
        $ranking->reorder($itemIds);
        $this->entityManager->flush();
    }

    public function toggleVisibility(Ranking $ranking): void
    {
        $ranking->setVisibility($ranking->isPublic() ? Visibility::Private : Visibility::Public);
        $this->entityManager->flush();
    }

    public function remove(Ranking $ranking, CatalogItem $item): void
    {
        $ranking->remove($item);
        $this->entityManager->flush();
    }

    public function delete(Ranking $ranking): void
    {
        $this->entityManager->remove($ranking);
        $this->entityManager->flush();
    }
}
