<?php

namespace App\Tests\Catalog;

use App\Catalog\CatalogImporter;
use App\Entity\Album;
use App\Entity\Artist;
use App\Entity\Track;
use App\Enum\ImportStatus;
use App\Message\ImportAlbumTracks;
use App\Message\ImportArtistDiscography;
use App\Tests\Fixtures\CatalogHttpMock;
use App\Tests\Support\CreatesEntities;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class CatalogImporterTest extends KernelTestCase
{
    use CreatesEntities;

    private CatalogImporter $importer;

    protected function setUp(): void
    {
        $this->importer = self::getContainer()->get(CatalogImporter::class);
    }

    public function testRequestArtistImportStoresArtistAndQueuesDiscography(): void
    {
        $artist = $this->importer->requestArtistImport(CatalogHttpMock::RADIOHEAD);

        self::assertNotNull($artist->getId());
        self::assertSame('Radiohead', $artist->getName());
        self::assertSame(ImportStatus::Pending, $artist->getImportStatus());

        $messages = $this->sentMessages();
        self::assertCount(1, $messages);
        self::assertInstanceOf(ImportArtistDiscography::class, $messages[0]);
        self::assertSame($artist->getId(), $messages[0]->artistId);
    }

    public function testImportDiscographyHidesLivesAndCompilations(): void
    {
        $artist = $this->importer->requestArtistImport(CatalogHttpMock::RADIOHEAD);
        $this->importer->importDiscography($artist);

        self::assertSame(ImportStatus::Done, $artist->getImportStatus());
        self::assertNotNull($artist->getImportedAt());

        $albums = self::em()->getRepository(Album::class)->findBy(['artist' => $artist]);
        $visible = array_map(static fn (Album $album) => $album->getName(), array_filter($albums, static fn (Album $album) => !$album->isHidden()));
        sort($visible);

        self::assertCount(5, $albums);
        self::assertSame(['Kid A', 'My Iron Lung', 'OK Computer'], $visible);

        // Seuls les albums visibles partent en import de titres.
        $queued = array_filter($this->sentMessages(), static fn (object $message) => $message instanceof ImportAlbumTracks);
        self::assertCount(3, $queued);
    }

    public function testReimportUpdatesWithoutDuplicatingAndKeepsAdminChoices(): void
    {
        $artist = $this->importer->requestArtistImport(CatalogHttpMock::RADIOHEAD);
        $this->importer->importDiscography($artist);

        $okComputer = $this->album('OK Computer');
        $okComputer->setHidden(true)->setName('nom modifié');
        self::em()->flush();

        $this->importer->importDiscography($artist);

        self::assertCount(5, self::em()->getRepository(Album::class)->findBy(['artist' => $artist]));
        self::assertSame(1, self::em()->getRepository(Artist::class)->count([]));
        self::assertSame('OK Computer', $okComputer->getName(), 'Les données de la source sont rafraîchies');
        self::assertTrue($okComputer->isHidden(), 'Le masquage décidé en back-office est conservé');
    }

    public function testImportTracksStoresTracklistEditionAndCover(): void
    {
        $artist = $this->importer->requestArtistImport(CatalogHttpMock::RADIOHEAD);
        $this->importer->importDiscography($artist);
        $okComputer = $this->album('OK Computer');

        $this->importer->importTracks($okComputer);
        $this->importer->importTracks($okComputer);

        $tracks = self::em()->getRepository(Track::class)->findBy(['album' => $okComputer], ['position' => 'ASC']);
        self::assertCount(12, $tracks, 'Un second import ne duplique pas les titres');
        self::assertSame('Airbag', $tracks[0]->getName());
        self::assertSame('4:44', $tracks[0]->getFormattedDuration());
        self::assertSame(CatalogHttpMock::OK_COMPUTER_FIRST_RELEASE, $okComputer->getExternalId('musicbrainz-edition'));
        self::assertNotNull($okComputer->getCoverUrl());
        self::assertNotNull($okComputer->getCoverPath());
        self::assertFileExists(self::getContainer()->getParameter('app.media_dir').'/'.$okComputer->getCoverPath());
        self::assertNotNull($okComputer->getTracksImportedAt());
    }

    private function album(string $name): Album
    {
        return self::em()->getRepository(Album::class)->findOneBy(['name' => $name]) ?? throw new \RuntimeException('Album absent : '.$name);
    }

    /**
     * @return list<object>
     */
    private function sentMessages(): array
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');

        return array_values(array_map(static fn ($envelope) => $envelope->getMessage(), $transport->getSent()));
    }
}
