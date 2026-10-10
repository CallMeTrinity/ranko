<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['ranking_id', 'item_id'])]
class RankingEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'entries')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Ranking $ranking,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private CatalogItem $item,
        /** Commence à 1. */
        #[ORM\Column]
        private int $position,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRanking(): Ranking
    {
        return $this->ranking;
    }

    public function getItem(): CatalogItem
    {
        return $this->item;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }
}
