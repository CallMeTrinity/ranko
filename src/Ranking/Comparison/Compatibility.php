<?php

namespace App\Ranking\Comparison;

use App\Entity\Ranking;

/**
 * Accord entre deux utilisateurs sur l'ensemble de leurs classements communs.
 */
final readonly class Compatibility
{
    /**
     * Classements comparables deux à deux, chacun avec au moins une paire d'éléments commune.
     *
     * @param list<array{mine: Ranking, theirs: Ranking, result: ComparisonResult}> $pairs
     */
    public function __construct(
        public array $pairs,
    ) {
    }

    public function isMeaningful(): bool
    {
        return [] !== $this->pairs;
    }

    /**
     * Part des paires d'éléments classées dans le même ordre, tous classements confondus.
     * Pondérée par le nombre de paires : un classement de 30 titres pèse plus qu'un de 3,
     * ce qu'une moyenne des pourcentages ignorerait.
     */
    public function agreement(): ?float
    {
        $concordant = 0;
        $total = 0;
        foreach ($this->pairs as $pair) {
            $concordant += $pair['result']->concordant;
            $total += $pair['result']->pairs();
        }

        return $total > 0 ? $concordant / $total : null;
    }
}
