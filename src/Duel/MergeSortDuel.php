<?php

namespace App\Duel;

/**
 * Tri fusion interactif, en version itérative pour que l'état soit sérialisable.
 *
 * Rigoureux : une file de "runs" triés. On retire les deux premiers, on les fusionne
 * en posant à chaque pas la question "tête de gauche ou tête de droite ?", et le run
 * fusionné repart en fin de file. Quand un côté est vide, le reste de l'autre est
 * recopié sans question. Fini quand il ne reste qu'un run.
 *
 * Accessible : on fait des petits tas triés de cartes, puis on fusionne les tas deux à
 * deux en ne comparant que les cartes du dessus.
 *
 * Au pire n·⌈log2 n⌉ − 2^⌈log2 n⌉ + 1 questions : 25 pour 10 éléments.
 */
final class MergeSortDuel implements DuelAlgorithm
{
    /**
     * @param list<list<int>> $queue  runs triés en attente de fusion
     * @param list<int>       $left   reste du run de gauche en cours de fusion
     * @param list<int>       $right  reste du run de droite en cours de fusion
     * @param list<int>       $merged début du run fusionné
     */
    private function __construct(
        private array $queue,
        private array $left,
        private array $right,
        private array $merged,
        private int $comparisons,
        private readonly int $size,
    ) {
    }

    /**
     * @param list<int> $itemIds ordre de départ (à mélanger avant pour éviter les biais)
     */
    public static function start(array $itemIds): self
    {
        $duel = new self(array_map(static fn (int $id): array => [$id], $itemIds), [], [], [], 0, \count($itemIds));
        $duel->advance();

        return $duel;
    }

    /**
     * @param array<string, mixed> $state
     */
    public static function fromState(array $state): self
    {
        /** @var array{queue: list<list<int>>, left: list<int>, right: list<int>, merged: list<int>, comparisons: int, size: int} $state */
        return new self($state['queue'], $state['left'], $state['right'], $state['merged'], $state['comparisons'], $state['size']);
    }

    public function toState(): array
    {
        return [
            'queue' => $this->queue,
            'left' => $this->left,
            'right' => $this->right,
            'merged' => $this->merged,
            'comparisons' => $this->comparisons,
            'size' => $this->size,
        ];
    }

    public function currentPair(): ?array
    {
        return [] === $this->left ? null : [$this->left[0], $this->right[0]];
    }

    public function answer(int $winnerId): void
    {
        $pair = $this->currentPair();
        if (null === $pair || !\in_array($winnerId, $pair, true)) {
            throw new \InvalidArgumentException(sprintf('%d ne fait pas partie de la question en cours.', $winnerId));
        }

        $this->merged[] = $winnerId;
        if ($winnerId === $pair[0]) {
            array_shift($this->left);
        } else {
            array_shift($this->right);
        }
        ++$this->comparisons;

        if ([] === $this->left || [] === $this->right) {
            $this->queue[] = [...$this->merged, ...$this->left, ...$this->right];
            $this->left = $this->right = $this->merged = [];
            $this->advance();
        }
    }

    public function isFinished(): bool
    {
        return null === $this->currentPair();
    }

    public function result(): array
    {
        if (!$this->isFinished()) {
            throw new \LogicException('Le duel n\'est pas terminé.');
        }

        return $this->queue[0] ?? [];
    }

    public function comparisons(): int
    {
        return $this->comparisons;
    }

    public function maxComparisons(): int
    {
        if ($this->size < 2) {
            return 0;
        }

        $levels = (int) ceil(log($this->size, 2));

        return $this->size * $levels - 2 ** $levels + 1;
    }

    /**
     * Prépare la prochaine fusion s'il reste au moins deux runs.
     */
    private function advance(): void
    {
        if (\count($this->queue) < 2) {
            return;
        }

        $this->left = array_shift($this->queue);
        $this->right = array_shift($this->queue);
    }
}
