<?php

namespace App\Tests\Command;

use App\Message\ImportArtistDiscography;
use App\Tests\Fixtures\CatalogHttpMock;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class ImportArtistCommandTest extends KernelTestCase
{
    public function testQueuesImport(): void
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:catalog:import-artist'));
        $tester->execute(['external-id' => CatalogHttpMock::RADIOHEAD]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Radiohead', $tester->getDisplay());

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(ImportArtistDiscography::class, $transport->getSent()[0]->getMessage());
    }
}
