<?php

namespace App\Tests\Catalog;

use App\Catalog\CatalogProviderInterface;
use App\Catalog\Exception\ItemNotFoundException;
use App\Enum\AlbumType;
use App\Tests\Fixtures\CatalogHttpMock;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Passe par le vrai service et ses clients scopés ; seul le transport HTTP est simulé.
 */
final class MusicBrainzProviderTest extends KernelTestCase
{
    private CatalogProviderInterface $provider;

    protected function setUp(): void
    {
        $this->provider = self::getContainer()->get(CatalogProviderInterface::class);
    }

    public function testSearchArtists(): void
    {
        $results = $this->provider->searchArtists('radiohead');

        self::assertCount(2, $results);
        self::assertSame(CatalogHttpMock::RADIOHEAD, $results[0]->externalId);
        self::assertSame('Radiohead', $results[0]->name);
        self::assertSame('GB', $results[0]->country);
    }

    public function testFetchUnknownArtistThrowsNotFound(): void
    {
        $this->expectException(ItemNotFoundException::class);

        $this->provider->fetchArtist(CatalogHttpMock::UNKNOWN);
    }

    public function testFetchAlbumsMapsTypes(): void
    {
        $albums = $this->provider->fetchAlbums(CatalogHttpMock::RADIOHEAD);
        $byTitle = array_column(array_map(static fn ($album) => ['title' => $album->title, 'album' => $album], $albums), 'album', 'title');

        self::assertCount(6, $albums);
        self::assertSame(AlbumType::Album, $byTitle['OK Computer']->type);
        self::assertSame('1997-05-21', $byTitle['OK Computer']->releaseDate);
        self::assertTrue($byTitle['OK Computer']->isMainRelease());
        self::assertSame(AlbumType::Ep, $byTitle['My Iron Lung']->type);
        self::assertTrue($byTitle['My Iron Lung']->isMainRelease());
        self::assertFalse($byTitle['5 Album Set']->isMainRelease());
        self::assertSame(AlbumType::Single, $byTitle['Creep']->type);
        self::assertFalse($byTitle['Creep']->isMainRelease(), 'Les singles arrivent masqués');
    }

    public function testTracklistComesFromEarliestOfficialEdition(): void
    {
        // La fixture contient un bootleg plus ancien et une édition officielle sans date :
        // les deux doivent être écartés au profit de l'édition japonaise du 21/05/1997.
        $tracklist = $this->provider->fetchTracklist(CatalogHttpMock::OK_COMPUTER);

        self::assertSame(CatalogHttpMock::OK_COMPUTER_FIRST_RELEASE, $tracklist->editionId);
        self::assertCount(12, $tracklist->tracks);
        self::assertSame('Airbag', $tracklist->tracks[0]->title);
        self::assertSame(1, $tracklist->tracks[0]->position);
        self::assertSame(1, $tracklist->tracks[0]->discNumber);
        self::assertSame(284400, $tracklist->tracks[0]->durationMs);
    }

    public function testSingleTracklistMergesAllEditions(): void
    {
        // CD1 : Dead Star, In Your World, Futurism. CD2 : In Your World, Dead Star, Can’t Take
        // My Eyes Off You. Le pressage français ajoute une instrumentale, le japonais deux lives
        // et un "Dead Star" enregistré en double chez MusicBrainz.
        $tracklist = $this->provider->fetchTracklist(CatalogHttpMock::DEAD_STAR);

        self::assertNull($tracklist->editionId);
        self::assertSame([
            'Dead Star',
            'In Your World',
            'Futurism',
            'Can’t Take My Eyes Off You',
            'Dead Star (instrumental)',
            'In Your World (live)',
            'Dead Star (live)',
        ], array_map(static fn ($track) => $track->title, $tracklist->tracks));
        self::assertSame(range(1, 7), array_map(static fn ($track) => $track->position, $tracklist->tracks));
    }

    public function testCoverUrl(): void
    {
        self::assertStringEndsWith('-1200.jpg', (string) $this->provider->fetchCoverUrl(CatalogHttpMock::OK_COMPUTER));
        self::assertNull($this->provider->fetchCoverUrl(CatalogHttpMock::UNKNOWN));
        self::assertNull($this->provider->fetchCoverUrl(CatalogHttpMock::BROKEN_COVER), 'Une panne du serveur de pochettes ne fait pas échouer l\'import');
    }
}
