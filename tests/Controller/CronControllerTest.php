<?php

namespace App\Tests\Controller;

use App\Catalog\CatalogImporter;
use App\Entity\Album;
use App\Entity\Artist;
use App\Enum\ImportStatus;
use App\Tests\Fixtures\CatalogHttpMock;
use App\Tests\Support\CreatesEntities;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Lock\LockFactory;

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

    public function testAnswersBusyWhenAWorkerIsAlreadyRunning(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $lock = self::getContainer()->get(LockFactory::class)->createLock('messenger-queue-runner');
        self::assertTrue($lock->acquire());

        $client->request('GET', '/cron/messenger?token=test-cron-secret');

        self::assertSame(['status' => 'busy'], json_decode((string) $client->getResponse()->getContent(), true));
        $lock->release();
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
        // La discographie, puis les albums qu'elle a mis en file, dans le même appel.
        // Seul OK Computer a ses fixtures : Kid A et My Iron Lung répondent 404 et
        // partent en échec définitif, sans retry.
        self::assertSame(['status' => 'ok', 'handled' => 2, 'retried' => 0, 'failed' => 2, 'remaining' => null], $body);

        $artist = self::em()->getRepository(Artist::class)->findOneBy(['name' => 'Radiohead']);
        self::assertSame(ImportStatus::Done, $artist?->getImportStatus());
        $okComputer = self::em()->getRepository(Album::class)->findOneBy(['name' => 'OK Computer']);
        self::assertCount(12, $okComputer?->getTracks() ?? []);
    }
}
