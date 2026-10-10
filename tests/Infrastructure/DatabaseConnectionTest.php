<?php

namespace App\Tests\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DatabaseConnectionTest extends KernelTestCase
{
    public function testConnectsToMariaDb(): void
    {
        $connection = self::getContainer()->get(Connection::class);

        self::assertInstanceOf(MariaDBPlatform::class, $connection->getDatabasePlatform());
        self::assertSame(1, $connection->fetchOne('SELECT 1'));
        self::assertStringEndsWith('_test', (string) $connection->getDatabase());
    }
}
