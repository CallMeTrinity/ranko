<?php

namespace App\Tests\Duel;

use App\Duel\MergeSortDuel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MergeSortDuelTest extends TestCase
{
    /**
     * Préférence cachée : un score par id. Le duel doit retrouver l'ordre des scores
     * sans jamais les voir, uniquement par des questions "A ou B ?".
     */
    #[DataProvider('sizes')]
    public function testSortsAccordingToHiddenPreferences(int $size): void
    {
        mt_srand($size);
        $scores = [];
        foreach (range(1, $size) as $id) {
            $scores[$id] = mt_rand();
        }
        $ids = array_keys($scores);
        shuffle($ids);

        $duel = MergeSortDuel::start($ids);
        while (null !== $pair = $duel->currentPair()) {
            // Aller-retour JSON à chaque question : la reprise doit être transparente.
            $duel = MergeSortDuel::fromState(json_decode((string) json_encode($duel->toState()), true));
            $duel->answer($scores[$pair[0]] >= $scores[$pair[1]] ? $pair[0] : $pair[1]);
        }

        arsort($scores);
        self::assertSame(array_keys($scores), $duel->result());
        self::assertLessThanOrEqual($duel->maxComparisons(), $duel->comparisons());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function sizes(): iterable
    {
        foreach ([0, 1, 2, 3, 5, 7, 10, 16, 17, 33, 50] as $size) {
            yield $size.' éléments' => [$size];
        }
    }

    public function testMaxComparisonsMatchesMergeSortWorstCase(): void
    {
        self::assertSame(25, MergeSortDuel::start(range(1, 10))->maxComparisons());
        self::assertSame(237, MergeSortDuel::start(range(1, 50))->maxComparisons());
    }

    public function testRejectsAWinnerOutsideTheCurrentPair(): void
    {
        $duel = MergeSortDuel::start([1, 2, 3]);

        $this->expectException(\InvalidArgumentException::class);
        $duel->answer(3);
    }
}
