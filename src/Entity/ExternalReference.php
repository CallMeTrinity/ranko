<?php

namespace App\Entity;

use App\Repository\ExternalReferenceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Identifiant d'un élément chez une source externe (MusicBrainz aujourd'hui, TMDB demain).
 * Le provider est un espace de noms : deux identifiants ne peuvent entrer en collision
 * que s'ils viennent du même provider.
 */
#[ORM\Entity(repositoryClass: ExternalReferenceRepository::class)]
#[ORM\UniqueConstraint(columns: ['provider', 'external_id'])]
#[ORM\UniqueConstraint(columns: ['item_id', 'provider'])]
class ExternalReference
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'externalReferences')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private CatalogItem $item,
        #[ORM\Column(length: 32)]
        private string $provider,
        #[ORM\Column(length: 64)]
        private string $externalId,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getItem(): CatalogItem
    {
        return $this->item;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function setExternalId(string $externalId): static
    {
        $this->externalId = $externalId;

        return $this;
    }
}
