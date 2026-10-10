<?php

namespace App\Catalog;

use App\Catalog\Data\AlbumData;
use App\Catalog\Data\ArtistData;
use App\Catalog\Data\TrackData;
use App\Entity\Album;
use App\Entity\Artist;
use App\Entity\Track;
use App\Enum\ImportStatus;
use App\Message\ImportAlbumTracks;
use App\Message\ImportArtistDiscography;
use App\Repository\ExternalReferenceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Synchronise le catalogue local avec la source externe. Idempotent : réimporter met à
 * jour les éléments existants (retrouvés par leur ExternalReference) sans dupliquer, et
 * ne touche jamais au choix "masqué" fait dans le back-office.
 */
final class CatalogImporter
{
    public function __construct(
        private readonly CatalogProviderInterface $provider,
        private readonly ExternalReferenceRepository $references,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Enregistre l'artiste tout de suite (il apparaît dans le back-office) et met
     * l'import de sa discographie en file.
     */
    public function requestArtistImport(string $externalId): Artist
    {
        $artist = $this->upsertArtist($this->provider->fetchArtist($externalId));
        $this->entityManager->flush();

        $this->requestDiscographyImport($artist);

        return $artist;
    }

    public function requestDiscographyImport(Artist $artist): void
    {
        $artist->setImportStatus(ImportStatus::Pending);
        $this->entityManager->flush();

        $this->bus->dispatch(new ImportArtistDiscography((int) $artist->getId()));
    }

    public function requestTracksImport(Album $album): void
    {
        $this->bus->dispatch(new ImportAlbumTracks((int) $album->getId()));
    }

    public function importDiscography(Artist $artist): void
    {
        $externalId = $this->externalIdOf($artist);

        $artist->setImportStatus(ImportStatus::Running);
        $this->entityManager->flush();

        try {
            $this->upsertArtist($this->provider->fetchArtist($externalId));

            $albums = array_map(
                fn (AlbumData $data): Album => $this->upsertAlbum($artist, $data),
                $this->provider->fetchAlbums($externalId),
            );

            $artist->setImportStatus(ImportStatus::Done)->setImportedAt($this->clock->now());
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $artist->setImportStatus(ImportStatus::Failed);
            $this->entityManager->flush();

            throw $exception;
        }

        // Les albums masqués (lives, compilations) n'ont pas besoin de leurs titres :
        // ils seront importés si un admin les rend visibles.
        foreach ($albums as $album) {
            if (!$album->isHidden() && null === $album->getTracksImportedAt()) {
                $this->requestTracksImport($album);
            }
        }
    }

    public function importTracks(Album $album): void
    {
        $albumExternalId = $this->externalIdOf($album);

        $tracklist = $this->provider->fetchTracklist($albumExternalId, $album->getExternalId($this->editionProvider()));

        foreach ($tracklist->tracks as $data) {
            $this->upsertTrack($album, $data);
        }

        if (null !== $tracklist->editionId) {
            $album->setExternalId($this->editionProvider(), $tracklist->editionId);
        }

        $album
            ->setCoverUrl($this->provider->fetchCoverUrl($albumExternalId) ?? $album->getCoverUrl())
            ->setTracksImportedAt($this->clock->now());

        $this->entityManager->flush();
    }

    private function upsertArtist(ArtistData $data): Artist
    {
        $artist = $this->references->findItem(Artist::class, $this->provider->getName(), $data->externalId);

        if (null === $artist) {
            $artist = new Artist($data->name);
            $artist->setExternalId($this->provider->getName(), $data->externalId);
            $this->entityManager->persist($artist);
        }

        return $artist
            ->setName($data->name)
            ->setSortName($data->sortName)
            ->setDisambiguation($data->disambiguation);
    }

    private function upsertAlbum(Artist $artist, AlbumData $data): Album
    {
        $album = $this->references->findItem(Album::class, $this->provider->getName(), $data->externalId);

        if (null === $album) {
            $album = new Album($artist, $data->title);
            $album->setExternalId($this->provider->getName(), $data->externalId);
            $album->setHidden(!$data->isMainRelease());
            $this->entityManager->persist($album);
        }

        return $album
            ->setName($data->title)
            ->setType($data->type)
            ->setSecondaryTypes($data->secondaryTypes)
            ->setReleaseDate($data->releaseDate);
    }

    private function upsertTrack(Album $album, TrackData $data): Track
    {
        $track = $this->references->findItem(Track::class, $this->provider->getName(), $data->externalId);

        if (null === $track) {
            $track = new Track($album, $data->title);
            $track->setExternalId($this->provider->getName(), $data->externalId);
            $this->entityManager->persist($track);
        }

        return $track
            ->setName($data->title)
            ->setDiscNumber($data->discNumber)
            ->setPosition($data->position)
            ->setDurationMs($data->durationMs);
    }

    private function externalIdOf(Artist|Album $item): string
    {
        return $item->getExternalId($this->provider->getName())
            ?? throw new \LogicException(sprintf('"%s" n\'a pas d\'identifiant %s.', $item->getName(), $this->provider->getName()));
    }

    /**
     * L'édition (release MusicBrainz) dont viennent les titres, gardée pour que les
     * réimports retombent sur les mêmes ids de titres.
     */
    private function editionProvider(): string
    {
        return $this->provider->getName().'-edition';
    }
}
