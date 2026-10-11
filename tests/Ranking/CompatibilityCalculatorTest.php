<?php

namespace App\Tests\Ranking;

use App\Entity\Album;
use App\Entity\Ranking;
use App\Entity\User;
use App\Enum\CatalogItemKind;
use App\Ranking\Comparison\CompatibilityCalculator;
use App\Ranking\RankingManager;
use App\Repository\RankingRepository;
use App\Tests\Support\CreatesEntities;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CompatibilityCalculatorTest extends KernelTestCase
{
    use CreatesEntities;

    private CompatibilityCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = self::getContainer()->get(CompatibilityCalculator::class);
    }

    public function testPairsRankingsOnSameKindAndScopeAndWeightsByPairs(): void
    {
        $artist = self::createArtist();
        $okComputer = self::createAlbum($artist, 'OK Computer', ['Airbag', 'Paranoid Android', 'Let Down']);
        $kidA = self::createAlbum($artist, 'Kid A', ['Everything', 'Kid A', 'National Anthem', 'Idioteque']);
        $alice = self::createUser('alice');
        $bob = self::createUser('bob');

        // OK Computer : 1 paire inversée sur 3. Kid A : 0 sur 6. Total : 8 / 9.
        $this->ranking($alice, $okComputer, ['Airbag', 'Paranoid Android', 'Let Down']);
        $this->ranking($bob, $okComputer, ['Airbag', 'Let Down', 'Paranoid Android']);
        $this->ranking($alice, $kidA, ['Everything', 'Kid A', 'National Anthem', 'Idioteque']);
        $this->ranking($bob, $kidA, ['Everything', 'Kid A', 'National Anthem', 'Idioteque']);
        // Même type, autre périmètre : jamais apparié à un classement d'album.
        self::getContainer()->get(RankingManager::class)->findOrCreate($bob, CatalogItemKind::Track, $artist);

        $compatibility = $this->calculator->between($alice, self::getContainer()->get(RankingRepository::class)->findByOwner($bob));

        self::assertCount(2, $compatibility->pairs);
        self::assertEqualsWithDelta(8 / 9, $compatibility->agreement(), 1e-9, 'Pondéré par paires, pas (2/3 + 1) / 2');
    }

    public function testRankingsWithoutCommonPairAreIgnored(): void
    {
        $album = self::createAlbum(self::createArtist(), trackNames: ['Airbag', 'Paranoid Android']);
        $alice = self::createUser('alice');
        $bob = self::createUser('bob');
        $manager = self::getContainer()->get(RankingManager::class);
        // Classements globaux d'un seul élément chacun : aucune paire à comparer.
        $airbag = $album->getTracks()->first() ?: throw new \LogicException('Album sans titre.');
        $manager->addToGlobalRanking($alice, $airbag);
        $theirs = $manager->addToGlobalRanking($bob, $airbag);

        $compatibility = $this->calculator->between($alice, [$theirs]);

        self::assertFalse($compatibility->isMeaningful());
        self::assertNull($compatibility->agreement());
    }

    /**
     * @param list<string> $order
     */
    private function ranking(User $owner, Album $album, array $order): Ranking
    {
        $ranking = self::getContainer()->get(RankingManager::class)->findOrCreate($owner, CatalogItemKind::Track, $album);
        $byName = [];
        foreach ($ranking->getItems() as $item) {
            $byName[$item->getName()] = $item;
        }
        $ranking->reorder(array_map(static fn (string $name): int => (int) $byName[$name]->getId(), $order));
        self::em()->flush();

        return $ranking;
    }
}
