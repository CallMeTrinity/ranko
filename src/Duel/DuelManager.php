<?php

namespace App\Duel;

use App\Entity\CatalogItem;
use App\Entity\Duel;
use App\Entity\Ranking;
use App\Enum\DuelType;
use App\Repository\CatalogItemRepository;
use App\Repository\DuelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Random\Randomizer;

final class DuelManager
{
    public function __construct(
        private readonly DuelRepository $duels,
        private readonly CatalogItemRepository $catalog,
        private readonly EntityManagerInterface $entityManager,
        private readonly Randomizer $randomizer = new Randomizer(),
    ) {
    }

    public function findActive(Ranking $ranking): ?Duel
    {
        return $this->duels->findOneByRanking($ranking);
    }

    /**
     * Reclasse tout le classement par tri fusion. L'ordre de départ est mélangé : partir
     * de l'ordre actuel biaiserait les premières questions sans en économiser aucune.
     */
    public function startSort(Ranking $ranking): Duel
    {
        $ids = $this->idsOf($ranking->getItems());
        if (\count($ids) < 2) {
            throw new \InvalidArgumentException('Il faut au moins deux éléments pour un duel.');
        }

        return $this->replaceActiveDuel(new Duel($ranking, DuelType::Sort, MergeSortDuel::start($this->randomizer->shuffleArray($ids))));
    }

    /**
     * Place des éléments par insertion dichotomique. Un élément déjà classé est retiré
     * de la liste puis replacé ; un nouvel élément n'entre dans le classement qu'à la
     * fin du duel.
     *
     * @param list<CatalogItem> $items
     */
    public function startInsertion(Ranking $ranking, array $items): Duel
    {
        foreach ($items as $item) {
            if (!$ranking->accepts($item)) {
                throw new \InvalidArgumentException(sprintf('"%s" ne peut pas entrer dans ce classement.', $item->getName()));
            }
        }

        $newIds = array_values(array_unique($this->idsOf($items)));
        if ([] === $newIds) {
            throw new \InvalidArgumentException('Aucun élément à placer.');
        }

        $sortedIds = array_values(array_diff($this->idsOf($ranking->getItems()), $newIds));

        return $this->replaceActiveDuel(new Duel($ranking, DuelType::Insertion, BinaryInsertionDuel::start($sortedIds, $newIds)));
    }

    /**
     * @return bool vrai si cette réponse a terminé le duel (le classement est alors à jour)
     */
    public function answer(Duel $duel, int $leftId, int $rightId, int $winnerId): bool
    {
        if (!$duel->answer($leftId, $rightId, $winnerId)) {
            return false;
        }

        if ($duel->getAlgorithm()->isFinished()) {
            $this->finish($duel);

            return true;
        }

        $this->entityManager->flush();

        return false;
    }

    public function undo(Duel $duel): void
    {
        $duel->undo();
        $this->entityManager->flush();
    }

    public function abandon(Duel $duel): void
    {
        $this->entityManager->remove($duel);
        $this->entityManager->flush();
    }

    /**
     * Le classement a pu changer pendant le duel (élément retiré depuis un autre
     * onglet) : on applique le résultat sur ce qui existe encore, et ce que le duel ne
     * connaît pas garde sa place relative, en fin de liste.
     */
    private function finish(Duel $duel): void
    {
        $ranking = $duel->getRanking();
        $result = $duel->getAlgorithm()->result();

        foreach ($result as $id) {
            $item = $this->catalog->find($id);
            if (null !== $item && !$ranking->contains($item) && $ranking->accepts($item)) {
                $ranking->add($item);
            }
        }

        $currentIds = $this->idsOf($ranking->getItems());
        $ranking->reorder([
            ...array_values(array_intersect($result, $currentIds)),
            ...array_values(array_diff($currentIds, $result)),
        ]);

        $this->entityManager->remove($duel);
        $this->entityManager->flush();
    }

    private function replaceActiveDuel(Duel $duel): Duel
    {
        // Suppression flushée d'abord : Doctrine exécute les INSERT avant les DELETE,
        // la contrainte "un duel par classement" sauterait sinon.
        $active = $this->findActive($duel->getRanking());
        if (null !== $active) {
            $this->entityManager->remove($active);
            $this->entityManager->flush();
        }

        if ($duel->getAlgorithm()->isFinished()) {
            // Rien à demander (ex. insertion dans un classement vide) : on applique direct.
            $this->entityManager->persist($duel);
            $this->finish($duel);

            return $duel;
        }

        $this->entityManager->persist($duel);
        $this->entityManager->flush();

        return $duel;
    }

    /**
     * @param list<CatalogItem> $items
     *
     * @return list<int>
     */
    private function idsOf(array $items): array
    {
        return array_map(static fn (CatalogItem $item): int => $item->getId() ?? throw new \LogicException('Élément non persisté.'), $items);
    }
}
