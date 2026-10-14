<?php

namespace App\Tests\Entity;

use App\Entity\Album;
use App\Entity\Artist;
use App\Entity\CatalogItem;
use App\Entity\Ranking;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\CatalogItemKind;
use PHPUnit\Framework\TestCase;

final class RankingTest extends TestCase
{
    private Artist $artist;
    private Album $album;

    protected function setUp(): void
    {
        $this->artist = $this->withId(new Artist('Radiohead'), 1);
        $this->album = $this->withId(new Album($this->artist, 'OK Computer'), 2);
    }

    public function testRejectsInvalidScope(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Ranking(new User('a@example.com', 'alice'), CatalogItemKind::Artist, $this->album);
    }

    public function testTitle(): void
    {
        $owner = new User('a@example.com', 'alice');

        self::assertSame('Top albums', (new Ranking($owner, CatalogItemKind::Album))->getTitle());
        self::assertSame('Titres de OK Computer', (new Ranking($owner, CatalogItemKind::Track, $this->album))->getTitle());
    }

    public function testCustomListIsNamedAndRefusesItemsOnceFull(): void
    {
        [$a, $b, $c] = $this->tracks('A', 'B', 'C');
        $ranking = new Ranking(new User('a@example.com', 'alice'), CatalogItemKind::Track, $this->album, 'Pour courir', 2);
        $ranking->add($a);
        $ranking->add($b, position: 1);

        self::assertTrue($ranking->isCustom());
        self::assertSame('Pour courir', $ranking->getTitle());
        self::assertTrue($ranking->isFull());

        $ranking->add($a);
        self::assertSame(['B', 'A'], $this->names($ranking), 'Ajouter un élément déjà présent ne compte pas comme une place');

        $this->expectException(\InvalidArgumentException::class);
        $ranking->add($c);
    }

    public function testRenameCustomListCountsAsAChange(): void
    {
        $ranking = new Ranking(new User('a@example.com', 'alice'), CatalogItemKind::Track, null, 'Pour courir');
        $updatedAt = $ranking->getUpdatedAt();
        usleep(1);

        $ranking->rename('  Pour le trail  ');

        self::assertSame('Pour le trail', $ranking->getTitle());
        self::assertGreaterThan($updatedAt, $ranking->getUpdatedAt());

        $this->expectException(\InvalidArgumentException::class);
        $ranking->rename(' ');
    }

    public function testAutomaticRankingCannotBeRenamed(): void
    {
        $this->expectException(\LogicException::class);

        (new Ranking(new User('a@example.com', 'alice'), CatalogItemKind::Album))->rename('Mes albums');
    }

    /**
     * @return iterable<string, array{?string, ?int}>
     */
    public static function invalidCustomLists(): iterable
    {
        yield 'nom vide' => ['  ', null];
        yield 'zéro place' => ['Top', 0];
        yield 'places sans nom' => [null, 10];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCustomLists')]
    public function testRejectsInvalidCustomList(?string $name, ?int $maxSize): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Ranking(new User('a@example.com', 'alice'), CatalogItemKind::Track, null, $name, $maxSize);
    }

    public function testAddAppendsOrInsertsAndShifts(): void
    {
        [$a, $b, $c] = $this->tracks('A', 'B', 'C');
        $ranking = $this->ranking();

        $ranking->add($a);
        $ranking->add($b);
        $ranking->add($c, position: 1);
        $ranking->add($a);

        self::assertSame(['C', 'A', 'B'], $this->names($ranking));
        self::assertSame([1, 2, 3], $this->positions($ranking));
    }

    public function testAddRefusesItemOutsideScopeOrOfOtherKind(): void
    {
        $otherAlbum = $this->withId(new Album($this->artist, 'Kid A'), 3);
        $foreign = $this->withId(new Track($otherAlbum, 'Idioteque'), 99);
        $ranking = $this->ranking();

        self::assertFalse($ranking->accepts($foreign));
        self::assertFalse($ranking->accepts($otherAlbum));

        $this->expectException(\InvalidArgumentException::class);
        $ranking->add($foreign);
    }

    public function testRemoveCompactsPositions(): void
    {
        [$a, $b, $c] = $this->tracks('A', 'B', 'C');
        $ranking = $this->ranking($a, $b, $c);

        $ranking->remove($b);

        self::assertSame(['A', 'C'], $this->names($ranking));
        self::assertSame([1, 2], $this->positions($ranking));
    }

    public function testReorder(): void
    {
        [$a, $b, $c] = $this->tracks('A', 'B', 'C');
        $ranking = $this->ranking($a, $b, $c);

        $ranking->reorder([(int) $c->getId(), (int) $a->getId(), (int) $b->getId()]);

        self::assertSame(['C', 'A', 'B'], $this->names($ranking));
    }

    /**
     * @param list<int> $ids
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidOrders')]
    public function testReorderRejectsAnythingButAPermutation(array $ids): void
    {
        [$a, $b, $c] = $this->tracks('A', 'B', 'C');
        $ranking = $this->ranking($a, $b, $c);

        $this->expectException(\InvalidArgumentException::class);
        $ranking->reorder($ids);
    }

    /**
     * @return iterable<string, array{list<int>}>
     */
    public static function invalidOrders(): iterable
    {
        yield 'élément manquant' => [[10, 11]];
        yield 'doublon' => [[10, 10, 11]];
        yield 'élément étranger' => [[10, 11, 99]];
        yield 'élément en trop' => [[10, 11, 12, 13]];
    }

    /**
     * @return list<Track>
     */
    private function tracks(string ...$names): array
    {
        $tracks = [];
        foreach (array_values($names) as $index => $name) {
            $tracks[] = $this->withId(new Track($this->album, $name), 10 + $index);
        }

        return $tracks;
    }

    private function ranking(CatalogItem ...$items): Ranking
    {
        $ranking = new Ranking(new User('a@example.com', 'alice'), CatalogItemKind::Track, $this->album);
        foreach ($items as $item) {
            $ranking->add($item);
        }

        return $ranking;
    }

    /**
     * @return list<string>
     */
    private function names(Ranking $ranking): array
    {
        return array_map(static fn (CatalogItem $item): string => $item->getName(), $ranking->getItems());
    }

    /**
     * @return list<int>
     */
    private function positions(Ranking $ranking): array
    {
        $positions = $ranking->getEntries()->map(static fn ($entry): int => $entry->getPosition())->toArray();
        sort($positions);

        return $positions;
    }

    /**
     * @template T of CatalogItem
     *
     * @param T $item
     *
     * @return T
     */
    private function withId(CatalogItem $item, int $id): CatalogItem
    {
        (new \ReflectionProperty(CatalogItem::class, 'id'))->setValue($item, $id);

        return $item;
    }
}
