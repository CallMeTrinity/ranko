<?php

namespace App\Entity;

use App\Enum\CatalogItemKind;
use App\Repository\TrackRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TrackRepository::class)]
class Track extends CatalogItem
{
    #[ORM\Column]
    private int $discNumber = 1;

    #[ORM\Column]
    private int $position = 1;

    #[ORM\Column(nullable: true)]
    private ?int $durationMs = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'tracks')]
        #[ORM\JoinColumn(nullable: false)]
        private Album $album,
        string $name,
    ) {
        parent::__construct($name);
    }

    public function getKind(): CatalogItemKind
    {
        return CatalogItemKind::Track;
    }

    public function getParent(): Album
    {
        return $this->album;
    }

    public function getAlbum(): Album
    {
        return $this->album;
    }

    public function getDiscNumber(): int
    {
        return $this->discNumber;
    }

    public function setDiscNumber(int $discNumber): static
    {
        $this->discNumber = $discNumber;

        return $this;
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

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    public function setDurationMs(?int $durationMs): static
    {
        $this->durationMs = $durationMs;

        return $this;
    }

    public function getFormattedDuration(): ?string
    {
        if (null === $this->durationMs) {
            return null;
        }

        $seconds = intdiv($this->durationMs, 1000);

        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
