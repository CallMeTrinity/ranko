<?php

namespace App\Entity;

use App\Enum\AlbumType;
use App\Enum\CatalogItemKind;
use App\Repository\AlbumRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AlbumRepository::class)]
class Album extends CatalogItem
{
    #[ORM\Column(length: 16, enumType: AlbumType::class)]
    private AlbumType $type = AlbumType::Album;

    /**
     * Types secondaires de la source (Live, Compilation, Remix...), gardés bruts.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $secondaryTypes = [];

    /**
     * Date de première sortie, précision variable : "1997", "1997-05" ou "1997-05-21".
     * Le format ISO garde l'ordre lexicographique, donc le tri SQL reste correct.
     */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $releaseDate = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $coverUrl = null;

    /**
     * Copie locale de la pochette, relative au dossier des médias (voir App\Media\ImageStore).
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $coverPath = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $tracksImportedAt = null;

    /**
     * Cascade côté ORM et non en SQL : avec l'héritage JOINED, un ON DELETE CASCADE
     * supprimerait la ligne "track" mais laisserait sa ligne "catalog_item" orpheline.
     *
     * @var Collection<int, Track>
     */
    #[ORM\OneToMany(targetEntity: Track::class, mappedBy: 'album', cascade: ['remove'])]
    #[ORM\OrderBy(['discNumber' => 'ASC', 'position' => 'ASC'])]
    private Collection $tracks;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'albums')]
        #[ORM\JoinColumn(nullable: false)]
        private Artist $artist,
        string $name,
    ) {
        parent::__construct($name);
        $this->tracks = new ArrayCollection();
    }

    public function getKind(): CatalogItemKind
    {
        return CatalogItemKind::Album;
    }

    public function getParent(): Artist
    {
        return $this->artist;
    }

    public function getArtist(): Artist
    {
        return $this->artist;
    }

    public function getType(): AlbumType
    {
        return $this->type;
    }

    public function setType(AlbumType $type): static
    {
        $this->type = $type;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getSecondaryTypes(): array
    {
        return $this->secondaryTypes;
    }

    /**
     * @param list<string> $secondaryTypes
     */
    public function setSecondaryTypes(array $secondaryTypes): static
    {
        $this->secondaryTypes = $secondaryTypes;

        return $this;
    }

    public function getReleaseDate(): ?string
    {
        return $this->releaseDate;
    }

    public function setReleaseDate(?string $releaseDate): static
    {
        $this->releaseDate = $releaseDate;

        return $this;
    }

    public function getReleaseYear(): ?string
    {
        return null === $this->releaseDate ? null : substr($this->releaseDate, 0, 4);
    }

    public function getCoverUrl(): ?string
    {
        return $this->coverUrl;
    }

    public function setCoverUrl(?string $coverUrl): static
    {
        $this->coverUrl = $coverUrl;

        return $this;
    }

    public function getCoverPath(): ?string
    {
        return $this->coverPath;
    }

    public function setCoverPath(?string $coverPath): static
    {
        $this->coverPath = $coverPath;

        return $this;
    }

    public function getTracksImportedAt(): ?\DateTimeImmutable
    {
        return $this->tracksImportedAt;
    }

    public function setTracksImportedAt(?\DateTimeImmutable $tracksImportedAt): static
    {
        $this->tracksImportedAt = $tracksImportedAt;

        return $this;
    }

    /**
     * @return Collection<int, Track>
     */
    public function getTracks(): Collection
    {
        return $this->tracks;
    }
}
