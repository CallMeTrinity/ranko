<?php

namespace App\Tests\Controller;

use App\Catalog\CatalogImporter;
use App\Entity\Album;
use App\Entity\Artist;
use App\Enum\ImportStatus;
use App\Tests\Fixtures\CatalogHttpMock;
use App\Tests\Support\CreatesEntities;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CronControllerTest extends WebTestCase
{
    use CreatesEntities;

    public function testRejectsMissingOrWrongToken(): void
    {
        $client = self::createClient();

        $client->request('GET', '/cron/messenger');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/cron/messenger?token=mauvais');
        self::assertResponseStatusCodeSame(403);
    }

    public function testConsumesTheQueueUntilEmpty(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        self::getContainer()->get(CatalogImporter::class)->requestArtistImport(CatalogHttpMock::RADIOHEAD);

        $start = microtime(true);
        $client->request('GET', '/cron/messenger?token=test-cron-secret');

        self::assertResponseIsSuccessful();
        self::assertLessThan(10, microtime(true) - $start, "S'arrête dès que la file est vide, sans attendre le budget de 20 s");

        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(['status' => 'ok', 'handled' => 1, 'retried' => 0, 'failed' => 0, 'remaining' => null], $body);

        $artist = self::em()->getRepository(Artist::class)->findOneBy(['name' => 'Radiohead']);
        self::assertSame(ImportStatus::Done, $artist?->getImportStatus());
        self::assertCount(5, self::em()->getRepository(Album::class)->findBy(['artist' => $artist]));
        // Les messages d'albums créés par ce handler ne sont pas traités ici : le
        // transport in-memory est vidé par le reset des services entre deux messages.
        // En prod (transport Doctrine), ils sont consommés dans le même appel.
    }
}
