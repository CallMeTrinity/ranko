<?php

namespace App\Entity;

use App\Enum\CatalogItemKind;
use App\Repository\CatalogItemRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Élément classable du catalogue. L'héritage permet à un classement, à son périmètre
 * et aux références externes de pointer vers un artiste, un album ou un titre sans
 * trois clés étrangères nullables.
 *
 * Attention : une association vers cette classe (et non vers une feuille) ne peut pas
 * être chargée en lazy, Doctrine doit lire le discriminant. Penser aux fetch joins.
 */
#[ORM\Entity(repositoryClass: CatalogItemRepository::class)]
#[ORM\InheritanceType('JOINED')]
#[ORM\DiscriminatorColumn(name: 'kind', type: 'string', length: 16)]
#[ORM\DiscriminatorMap([
    'artist' => Artist::class,
    'album' => Album::class,
    'track' => Track::class,
])]
#[ORM\Index(columns: ['hidden'])]
abstract class CatalogItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private bool $hidden = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, ExternalReference> */
    #[ORM\OneToMany(targetEntity: ExternalReference::class, mappedBy: 'item', cascade: ['persist'], orphanRemoval: true)]
    private Collection $externalReferences;

    public function __construct(
        #[ORM\Column(length: 255)]
        private string $name,
    ) {
        $this->createdAt = new \DateTimeImmutable();
        $this->externalReferences = new ArrayCollection();
    }

    abstract public function getKind(): CatalogItemKind;

    /**
     * Élément qui contient celui-ci : l'artiste d'un album, l'album d'un titre.
     */
    abstract public function getParent(): ?self;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function isHidden(): bool
    {
        return $this->hidden;
    }

    public function setHidden(bool $hidden): static
    {
        $this->hidden = $hidden;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Vrai si l'élément est le périmètre lui-même ou s'y trouve, à n'importe quelle profondeur.
     */
    public function isWithin(self $scope): bool
    {
        for ($item = $this; null !== $item; $item = $item->getParent()) {
            if ($item === $scope) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Collection<int, ExternalReference>
     */
    public function getExternalReferences(): Collection
    {
        return $this->externalReferences;
    }

    public function getExternalId(string $provider): ?string
    {
        foreach ($this->externalReferences as $reference) {
            if ($reference->getProvider() === $provider) {
                return $reference->getExternalId();
            }
        }

        return null;
    }

    public function setExternalId(string $provider, string $externalId): static
    {
        foreach ($this->externalReferences as $reference) {
            if ($reference->getProvider() === $provider) {
                $reference->setExternalId($externalId);

                return $this;
            }
        }

        $this->externalReferences->add(new ExternalReference($this, $provider, $externalId));

        return $this;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
