<?php

namespace App\Tests\Controller;

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
}
