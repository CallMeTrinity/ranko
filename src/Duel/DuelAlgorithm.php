<?php

namespace App\Duel;

/**
 * Tri interactif : l'algorithme pose des questions "A ou B ?" et avance à chaque
 * réponse. Les éléments sont des ids ; le résultat va du préféré au moins aimé.
 * L'état complet tient dans un tableau JSON, ce qui permet de reprendre un duel.
 */
interface DuelAlgorithm
{
    /**
     * @return array{int, int}|null la paire à départager, null quand c'est fini
     */
    public function currentPair(): ?array;

    /**
     * @throws \InvalidArgumentException si $winnerId n'est pas dans la paire courante
     */
    public function answer(int $winnerId): void;

    public function isFinished(): bool;

    /**
     * @return list<int>
     */
    public function result(): array;

    public function comparisons(): int;

    /**
     * Nombre de questions au pire, pour afficher une progression.
     */
    public function maxComparisons(): int;

    /**
     * @return array<string, mixed>
     */
    public function toState(): array;
}
