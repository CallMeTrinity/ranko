<?php

namespace App\Ranking\Comparison;

final readonly class ComparisonResult
{
    /**
     * @param list<int> $commonIds éléments communs, dans l'ordre du premier classement
     */
    public function __construct(
        public array $commonIds,
        public int $concordant,
        public int $discordant,
    ) {
    }

    public function pairs(): int
    {
        return $this->concordant + $this->discordant;
    }

    /**
     * Il faut au moins deux éléments communs, donc une paire, pour comparer.
     */
    public function isMeaningful(): bool
    {
        return $this->pairs() > 0;
    }

    /**
     * τ de Kendall, entre -1 et 1.
     */
    public function tau(): ?float
    {
        return $this->isMeaningful() ? ($this->concordant - $this->discordant) / $this->pairs() : null;
    }

    /**
     * Part des paires classées dans le même ordre, entre 0 et 1.
     */
    public function agreement(): ?float
    {
        return $this->isMeaningful() ? $this->concordant / $this->pairs() : null;
    }
}
