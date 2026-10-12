<?php

namespace App\Tests\Controller;

use App\Catalog\CatalogImporter;
use App\Tests\Support\CreatesEntities;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CoverTest extends WebTestCase
{
    use CreatesEntities;

    public function testAlbumPageServesResizedLocalCovers(): void
    {
        $client = self::createClient();
        $album = self::createAlbum(self::createArtist())
            ->setCoverUrl('https://coverartarchive.org/release/30702389-5c67-4438-9ea0-2351c8de0f1d/43287476628-1200.jpg');
        self::getContainer()->get(CatalogImporter::class)->storeCover($album);
        self::em()->flush();
        // Le chemin de la copie locale ne dépend que de l'URL : sans ce ménage, les variantes
        // d'un lancement précédent seraient servies directement, sans passer par resolve.
        self::getContainer()->get(CacheManager::class)->remove($album->getCoverPath());

        $client->request('GET', '/albums/'.$album->getId());

        self::assertResponseIsSuccessful();
        $img = $client->getCrawler()->filter('img[alt="Pochette de OK Computer"]');
        self::assertStringContainsString('/media/cache/resolve/square_128/covers/', (string) $img->attr('srcset'));
        self::assertStringContainsString(' 1024w', (string) $img->attr('srcset'));
        self::assertSame('(min-width: 640px) 224px, 100vw', $img->attr('sizes'));

        // Première demande d'une variante : générée, puis redirection vers le fichier statique.
        $client->request('GET', (string) parse_url((string) $img->attr('src'), \PHP_URL_PATH));

        self::assertResponseRedirects();
        $variant = self::getContainer()->getParameter('kernel.cache_dir').'/public/media/cache/square_256/'.$album->getCoverPath();
        self::assertFileExists($variant);
        self::assertSame(\IMAGETYPE_JPEG, getimagesize($variant)[2] ?? null);
    }
}
