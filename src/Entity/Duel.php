<?php

namespace App\Entity;

use App\Duel\BinaryInsertionDuel;
use App\Duel\DuelAlgorithm;
use App\Duel\MergeSortDuel;
use App\Enum\DuelType;
use App\Repository\DuelRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Duel en cours sur un classement. L'état de l'algorithme est persisté après chaque
 * réponse : on peut fermer l'onglet et reprendre plus tard. Un seul duel par
 * classement ; une fois terminé, il est appliqué au classement puis supprimé.
 */
#[ORM\Entity(repositoryClass: DuelRepository::class)]
#[ORM\UniqueConstraint(columns: ['ranking_id'])]
class Duel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Verrou optimiste : deux réponses envoyées en même temps (double clic, deux
     * onglets) ne peuvent pas écraser l'état l'une de l'autre.
     */
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $state;

    /**
     * État avant la dernière réponse, pour pouvoir l'annuler.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $previousState = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Ranking $ranking,
        #[ORM\Column(length: 16, enumType: DuelType::class)]
        private DuelType $type,
        DuelAlgorithm $algorithm,
    ) {
        $this->state = $algorithm->toState();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRanking(): Ranking
    {
        return $this->ranking;
    }

    public function getType(): DuelType
    {
        return $this->type;
    }

    public function getAlgorithm(): DuelAlgorithm
    {
        return match ($this->type) {
            DuelType::Sort => MergeSortDuel::fromState($this->state),
            DuelType::Insertion => BinaryInsertionDuel::fromState($this->state),
        };
    }

    /**
     * Applique une réponse. La paire affichée est renvoyée par le client : si elle ne
     * correspond plus à la question en cours (réponse rejouée, autre onglet), on ignore.
     *
     * @return bool vrai si la réponse a été prise en compte
     */
    public function answer(int $leftId, int $rightId, int $winnerId): bool
    {
        $algorithm = $this->getAlgorithm();
        if ($algorithm->currentPair() !== [$leftId, $rightId]) {
            return false;
        }

        $previous = $this->state;
        $algorithm->answer($winnerId);

        $this->previousState = $previous;
        $this->state = $algorithm->toState();
        $this->updatedAt = new \DateTimeImmutable();

        return true;
    }

    public function canUndo(): bool
    {
        return null !== $this->previousState;
    }

    public function undo(): void
    {
        if (null === $this->previousState) {
            return;
        }

        $this->state = $this->previousState;
        $this->previousState = null;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
