<?php

namespace App\Tests\Controller;

use App\Tests\Fixtures\CatalogHttpMock;
use App\Tests\Support\CreatesEntities;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CatalogControllerTest extends WebTestCase
{
    use CreatesEntities;

    public function testBrowseArtistThenAlbum(): void
    {
        $client = self::createClient();
        $artist = self::createArtist();
        self::createArtist('Masqué', hidden: true);
        self::createAlbum($artist, 'OK Computer', ['Airbag', 'Paranoid Android']);
        self::createAlbum($artist, 'Live', hidden: true);

        $client->request('GET', '/artists?q=radio');
        self::assertSelectorTextContains('main', 'Radiohead');
        self::assertSelectorTextNotContains('main', 'Masqué');

        $client->clickLink('Radiohead');
        self::assertSelectorTextContains('h1', 'Radiohead');
        self::assertSelectorTextNotContains('main', 'Live');

        $client->clickLink('OK Computer');
        self::assertSelectorTextContains('ol', 'Paranoid Android');
    }

    public function testHiddenItemsAreNotFound(): void
    {
        $client = self::createClient();
        $hiddenArtist = self::createArtist('Masqué', hidden: true);
        $albumOfHiddenArtist = self::createAlbum($hiddenArtist);

        $client->request('GET', '/artists/'.$hiddenArtist->getId());
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/albums/'.$albumOfHiddenArtist->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testPreviewRedirectsToTheDeezerExcerpt(): void
    {
        $client = self::createClient();
        $album = self::createAlbum(self::createArtist(), 'OK Computer', ['Airbag', 'Titre absent de Deezer']);
        [$airbag, $missing] = $album->getTracks()->toArray();

        $client->request('GET', '/albums/'.$album->getId());
        self::assertSelectorExists(sprintf('[data-preview-url-value="/tracks/%d/preview"]', $airbag->getId()));

        $client->request('GET', '/tracks/'.$airbag->getId().'/preview');
        self::assertResponseRedirects('https://cdnt-preview.dzcdn.net/api/1/1/fixture/'.CatalogHttpMock::DEEZER_AIRBAG.'.mp3?hdnea=exp=0');

        $client->request('GET', '/tracks/'.$missing->getId().'/preview');
        self::assertResponseStatusCodeSame(404);
    }

    public function testNoPreviewForHiddenTracks(): void
    {
        $client = self::createClient();
        $album = self::createAlbum(self::createArtist(), 'OK Computer', ['Airbag'], hidden: true);

        $client->request('GET', '/tracks/'.$album->getTracks()->first()->getId().'/preview');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTracklistFrame(): void
    {
        $client = self::createClient();
        $artist = self::createArtist();
        $album = self::createAlbum($artist, 'OK Computer', ['Airbag', 'Paranoid Android']);
        $notImported = self::createAlbum($artist, 'Kid A');

        $client->request('GET', '/albums/'.$album->getId().'/tracklist');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#tracklist-'.$album->getId());
        self::assertSelectorTextContains('ol', 'Paranoid Android');
        self::assertSelectorCount(2, '[data-controller="preview"]');

        $client->request('GET', '/albums/'.$notImported->getId().'/tracklist');
        self::assertSelectorTextContains('turbo-frame', 'Titres pas encore importés');
    }
}
