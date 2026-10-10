<?php

namespace App\Ranking\Comparison;

use App\Entity\CatalogItem;
use App\Entity\Ranking;

final class RankingComparator
{
    /**
     * Lignes : éléments communs dans l'ordre du premier classement, avec leur position
     * dans chacun des deux.
     *
     * @return array{result: ComparisonResult, rows: list<array{item: CatalogItem, first: int, second: int}>}
     */
    public function compare(Ranking $first, Ranking $second): array
    {
        $firstItems = $first->getItems();
        $secondIds = $this->ids($second->getItems());
        $result = KendallTau::compare($this->ids($firstItems), $secondIds);

        $secondPositions = array_flip($secondIds);
        $rows = [];
        foreach ($firstItems as $index => $item) {
            if (isset($secondPositions[(int) $item->getId()])) {
                $rows[] = ['item' => $item, 'first' => $index + 1, 'second' => $secondPositions[(int) $item->getId()] + 1];
            }
        }

        return ['result' => $result, 'rows' => $rows];
    }

    /**
     * @param list<CatalogItem> $items
     *
     * @return list<int>
     */
    private function ids(array $items): array
    {
        return array_map(static fn (CatalogItem $item): int => (int) $item->getId(), $items);
    }
}
