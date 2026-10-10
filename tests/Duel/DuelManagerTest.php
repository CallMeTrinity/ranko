<?php

namespace App\Tests\Duel;

use App\Duel\DuelManager;
use App\Entity\CatalogItem;
use App\Enum\CatalogItemKind;
use App\Ranking\RankingManager;
use App\Tests\Support\CreatesEntities;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DuelManagerTest extends KernelTestCase
{
    use CreatesEntities;

    public function testPlacingIntoAnEmptyRankingNeedsNoQuestion(): void
    {
        $ranking = self::getContainer()->get(RankingManager::class)->findOrCreate(self::createUser(), CatalogItemKind::Artist);
        $artist = self::createArtist();

        $duel = self::getContainer()->get(DuelManager::class)->startInsertion($ranking, [$artist]);

        self::assertTrue($duel->getAlgorithm()->isFinished());
        self::assertSame(['Radiohead'], array_map(static fn (CatalogItem $item) => $item->getName(), $ranking->getItems()));
    }

    public function testSortNeedsAtLeastTwoItems(): void
    {
        $album = self::createAlbum(self::createArtist(), trackNames: ['Airbag']);
        $ranking = self::getContainer()->get(RankingManager::class)->findOrCreate(self::createUser(), CatalogItemKind::Track, $album);

        $this->expectException(\InvalidArgumentException::class);
        self::getContainer()->get(DuelManager::class)->startSort($ranking);
    }
}
