<?php

namespace App\Duel;

/**
 * Insertion dichotomique dans une liste déjà triée, un élément après l'autre.
 *
 * On cherche la place de l'élément entre les bornes [low, high] : on le compare à
 * l'élément du milieu ; s'il gagne, sa place est avant (high = mid), sinon après
 * (low = mid + 1). Quand low = high, la place est trouvée. ⌈log2(n + 1)⌉ questions
 * au plus : 4 pour 15 titres.
 */
final class BinaryInsertionDuel implements DuelAlgorithm
{
    /**
     * @param list<int> $sorted  liste triée, du préféré au moins aimé
     * @param list<int> $pending éléments à insérer, le premier est en cours
     */
    private function __construct(
        private array $sorted,
        private array $pending,
        private int $low,
        private int $high,
        private int $comparisons,
        private readonly int $maxComparisons,
    ) {
    }

    /**
     * @param list<int> $sortedIds
     * @param list<int> $newIds
     */
    public static function start(array $sortedIds, array $newIds): self
    {
        $max = 0;
        foreach (array_keys($newIds) as $index) {
            $max += (int) ceil(log(\count($sortedIds) + $index + 1, 2));
        }

        $duel = new self($sortedIds, $newIds, 0, \count($sortedIds), 0, $max);
        $duel->settle();

        return $duel;
    }

    /**
     * @param array<string, mixed> $state
     */
    public static function fromState(array $state): self
    {
        /** @var array{sorted: list<int>, pending: list<int>, low: int, high: int, comparisons: int, max: int} $state */
        return new self($state['sorted'], $state['pending'], $state['low'], $state['high'], $state['comparisons'], $state['max']);
    }

    public function toState(): array
    {
        return [
            'sorted' => $this->sorted,
            'pending' => $this->pending,
            'low' => $this->low,
            'high' => $this->high,
            'comparisons' => $this->comparisons,
            'max' => $this->maxComparisons,
        ];
    }

    public function currentPair(): ?array
    {
        return [] === $this->pending ? null : [$this->pending[0], $this->sorted[$this->middle()]];
    }

    public function answer(int $winnerId): void
    {
        $pair = $this->currentPair();
        if (null === $pair || !\in_array($winnerId, $pair, true)) {
            throw new \InvalidArgumentException(sprintf('%d ne fait pas partie de la question en cours.', $winnerId));
        }

        if ($winnerId === $pair[0]) {
            $this->high = $this->middle();
        } else {
            $this->low = $this->middle() + 1;
        }
        ++$this->comparisons;

        $this->settle();
    }

    public function isFinished(): bool
    {
        return [] === $this->pending;
    }

    public function result(): array
    {
        if (!$this->isFinished()) {
            throw new \LogicException('Le duel n\'est pas terminé.');
        }

        return $this->sorted;
    }

    public function comparisons(): int
    {
        return $this->comparisons;
    }

    public function maxComparisons(): int
    {
        return $this->maxComparisons;
    }

    private function middle(): int
    {
        return intdiv($this->low + $this->high, 2);
    }

    /**
     * Insère l'élément courant dès que sa place est connue, puis passe au suivant.
     * Une liste vide accueille son premier élément sans question.
     */
    private function settle(): void
    {
        while ([] !== $this->pending && $this->low === $this->high) {
            array_splice($this->sorted, $this->low, 0, [array_shift($this->pending)]);
            $this->low = 0;
            $this->high = \count($this->sorted);
        }
    }
}
