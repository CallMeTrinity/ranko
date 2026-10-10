<?php

namespace App\Entity;

use App\Enum\CatalogItemKind;
use App\Enum\ImportStatus;
use App\Repository\ArtistRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ArtistRepository::class)]
class Artist extends CatalogItem
{
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sortName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $disambiguation = null;

    #[ORM\Column(length: 16, nullable: true, enumType: ImportStatus::class)]
    private ?ImportStatus $importStatus = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $importedAt = null;

    /**
     * Cascade côté ORM, voir Album::$tracks.
     *
     * @var Collection<int, Album>
     */
    #[ORM\OneToMany(targetEntity: Album::class, mappedBy: 'artist', cascade: ['remove'])]
    #[ORM\OrderBy(['releaseDate' => 'ASC'])]
    private Collection $albums;

    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->albums = new ArrayCollection();
    }

    public function getKind(): CatalogItemKind
    {
        return CatalogItemKind::Artist;
    }

    public function getParent(): ?CatalogItem
    {
        return null;
    }

    public function getSortName(): ?string
    {
        return $this->sortName;
    }

    public function setSortName(?string $sortName): static
    {
        $this->sortName = $sortName;

        return $this;
    }

    public function getDisambiguation(): ?string
    {
        return $this->disambiguation;
    }

    public function setDisambiguation(?string $disambiguation): static
    {
        $this->disambiguation = $disambiguation;

        return $this;
    }

    public function getImportStatus(): ?ImportStatus
    {
        return $this->importStatus;
    }

    public function setImportStatus(?ImportStatus $importStatus): static
    {
        $this->importStatus = $importStatus;

        return $this;
    }

    public function getImportedAt(): ?\DateTimeImmutable
    {
        return $this->importedAt;
    }

    public function setImportedAt(?\DateTimeImmutable $importedAt): static
    {
        $this->importedAt = $importedAt;

        return $this;
    }

    /**
     * @return Collection<int, Album>
     */
    public function getAlbums(): Collection
    {
        return $this->albums;
    }
}
