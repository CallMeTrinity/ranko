<?php

namespace App\Entity;

use App\Enum\CatalogItemKind;
use App\Enum\Visibility;
use App\Repository\RankingRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Classement générique : un type d'élément (artiste, album, titre) et un périmètre
 * optionnel (un artiste, un album). "Titres de OK Computer" = Track + scope OK Computer ;
 * "Top albums" = Album sans scope. Le n°1 d'un classement d'album est le titre préféré
 * de cet album : pas besoin de champ dédié.
 */
#[ORM\Entity(repositoryClass: RankingRepository::class)]
#[ORM\Index(columns: ['visibility', 'updated_at'])]
class Ranking
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16, enumType: Visibility::class)]
    private Visibility $visibility = Visibility::Private;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * EXTRA_LAZY : compter les entrées d'un classement dans une liste fait un COUNT
     * au lieu de charger toute la collection.
     *
     * @var Collection<int, RankingEntry>
     */
    #[ORM\OneToMany(targetEntity: RankingEntry::class, mappedBy: 'ranking', cascade: ['persist'], orphanRemoval: true, fetch: 'EXTRA_LAZY')]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $entries;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $owner,
        #[ORM\Column(length: 16, enumType: CatalogItemKind::class)]
        private CatalogItemKind $itemKind,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(onDelete: 'CASCADE')]
        private ?CatalogItem $scope = null,
    ) {
        if (null !== $scope && !\in_array($scope->getKind(), $itemKind->allowedScopeKinds(), true)) {
            throw new \InvalidArgumentException(sprintf('Un classement de type "%s" ne peut pas avoir un périmètre de type "%s".', $itemKind->value, $scope->getKind()->value));
        }

        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->entries = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getItemKind(): CatalogItemKind
    {
        return $this->itemKind;
    }

    public function getScope(): ?CatalogItem
    {
        return $this->scope;
    }

    public function getTitle(): string
    {
        $kind = match ($this->itemKind) {
            CatalogItemKind::Artist => 'artistes',
            CatalogItemKind::Album => 'albums',
            CatalogItemKind::Track => 'titres',
        };

        return null === $this->scope
            ? 'Top '.$kind
            : sprintf('%s de %s', ucfirst($kind), $this->scope->getName());
    }

    public function getVisibility(): Visibility
    {
        return $this->visibility;
    }

    public function isPublic(): bool
    {
        return Visibility::Public === $this->visibility;
    }

    public function setVisibility(Visibility $visibility): static
    {
        $this->visibility = $visibility;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return Collection<int, RankingEntry>
     */
    public function getEntries(): Collection
    {
        return $this->entries;
    }

    /**
     * Éléments dans l'ordre du classement.
     *
     * @return list<CatalogItem>
     */
    public function getItems(): array
    {
        $entries = $this->entries->toArray();
        usort($entries, static fn (RankingEntry $a, RankingEntry $b): int => $a->getPosition() <=> $b->getPosition());

        return array_map(static fn (RankingEntry $entry): CatalogItem => $entry->getItem(), $entries);
    }

    public function contains(CatalogItem $item): bool
    {
        return null !== $this->findEntry($item);
    }

    public function accepts(CatalogItem $item): bool
    {
        return $item->getKind() === $this->itemKind
            && (null === $this->scope || $item->isWithin($this->scope));
    }

    /**
     * Ajoute un élément à la position donnée (à la fin par défaut) et décale les suivants.
     */
    public function add(CatalogItem $item, ?int $position = null): void
    {
        if (!$this->accepts($item)) {
            throw new \InvalidArgumentException(sprintf('"%s" ne peut pas entrer dans le classement "%s".', $item->getName(), $this->getTitle()));
        }

        if ($this->contains($item)) {
            return;
        }

        $items = $this->getItems();
        $position = max(1, min($position ?? \count($items) + 1, \count($items) + 1));
        array_splice($items, $position - 1, 0, [$item]);

        $this->entries->add(new RankingEntry($this, $item, $position));
        $this->applyOrder($items);
    }

    public function remove(CatalogItem $item): void
    {
        $entry = $this->findEntry($item);
        if (null === $entry) {
            return;
        }

        $this->entries->removeElement($entry);
        $this->applyOrder($this->getItems());
    }

    /**
     * Nouvel ordre complet, exprimé en ids d'éléments du catalogue. Refuse tout ce qui
     * n'est pas une permutation exacte du contenu actuel : un client ne peut ni ajouter
     * ni perdre un élément en réordonnant.
     *
     * @param list<int> $itemIds
     */
    public function reorder(array $itemIds): void
    {
        $byId = [];
        foreach ($this->entries as $entry) {
            $item = $entry->getItem();
            $byId[$item->getId() ?? throw new \LogicException('Élément non persisté.')] = $item;
        }

        $sortedIds = $itemIds;
        sort($sortedIds);
        $currentIds = array_keys($byId);
        sort($currentIds);

        if ($sortedIds !== $currentIds) {
            throw new \InvalidArgumentException('Le nouvel ordre doit contenir exactement les éléments du classement.');
        }

        $this->applyOrder(array_map(static fn (int $id): CatalogItem => $byId[$id], $itemIds));
    }

    /**
     * @param list<CatalogItem> $items
     */
    private function applyOrder(array $items): void
    {
        foreach ($items as $index => $item) {
            $this->findEntry($item)?->setPosition($index + 1);
        }

        $this->updatedAt = new \DateTimeImmutable();
    }

    private function findEntry(CatalogItem $item): ?RankingEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->getItem() === $item) {
                return $entry;
            }
        }

        return null;
    }
}
