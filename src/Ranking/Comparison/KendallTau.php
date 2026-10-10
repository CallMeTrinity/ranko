<?php

namespace App\Ranking\Comparison;

/**
 * Corrélation de rang de Kendall entre deux classements, restreinte à leurs éléments communs.
 *
 * Rigoureux : pour chaque paire {x, y} d'éléments communs, la paire est concordante si
 * les deux classements mettent x et y dans le même ordre, discordante sinon (pas
 * d'ex aequo possible ici). τ = (C − D) / (C + D), entre −1 (ordres inversés) et 1
 * (ordres identiques). Le taux d'accord C / (C + D) = (τ + 1) / 2.
 *
 * Accessible : on prend toutes les paires d'éléments que les deux personnes ont
 * classés, et on compte dans quelle proportion elles tranchent pareil.
 *
 * O(n²) paires : 300 titres communs donnent 44 850 paires, ça reste instantané.
 */
final class KendallTau
{
    /**
     * @param list<int> $first  ids dans l'ordre du premier classement
     * @param list<int> $second ids dans l'ordre du second classement
     */
    public static function compare(array $first, array $second): ComparisonResult
    {
        $positionsInSecond = array_flip($second);
        // Éléments communs, dans l'ordre du premier classement.
        $common = array_values(array_filter($first, static fn (int $id): bool => isset($positionsInSecond[$id])));
        $n = \count($common);

        $concordant = $discordant = 0;
        for ($i = 0; $i < $n; ++$i) {
            for ($j = $i + 1; $j < $n; ++$j) {
                // Dans le premier classement, $common[$i] est devant $common[$j].
                if ($positionsInSecond[$common[$i]] < $positionsInSecond[$common[$j]]) {
                    ++$concordant;
                } else {
                    ++$discordant;
                }
            }
        }

        return new ComparisonResult($common, $concordant, $discordant);
    }
}
