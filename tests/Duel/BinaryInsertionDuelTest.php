<?php

namespace App\Tests\Duel;

use App\Duel\BinaryInsertionDuel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BinaryInsertionDuelTest extends TestCase
{
    /**
     * La liste 1..n est triée (1 = préféré) ; on insère un élément dont la place
     * cachée est $rank. Ids des nouveaux éléments : 100+.
     */
    #[DataProvider('insertions')]
    public function testInsertsAtTheRightPlace(int $size, float $rank): void
    {
        $sorted = $size > 0 ? range(1, $size) : [];
        $scores = array_combine($sorted, $sorted) + [100 => $rank];

        $duel = $this->play(BinaryInsertionDuel::start($sorted, [100]), $scores);

        asort($scores);
        self::assertSame(array_keys($scores), $duel->result());
        self::assertLessThanOrEqual((int) ceil(log($size + 1, 2)), $duel->comparisons());
    }

    /**
     * @return iterable<string, array{int, float}>
     */
    public static function insertions(): iterable
    {
        yield 'en tête' => [15, 0.5];
        yield 'en queue' => [15, 15.5];
        yield 'au milieu' => [15, 7.5];
        yield 'liste vide' => [0, 1.0];
        yield 'un seul élément' => [1, 0.5];
    }

    public function testInsertsSeveralItemsAndCountsFourQuestionsForFifteenTitles(): void
    {
        $scores = [1 => 1, 2 => 2, 3 => 3, 100 => 2.5, 101 => 0.5, 102 => 9];

        $duel = BinaryInsertionDuel::start([1, 2, 3], [100, 101, 102]);
        self::assertSame(2 + 3 + 3, $duel->maxComparisons());
        $duel = $this->play($duel, $scores);

        self::assertSame([101, 1, 2, 100, 3, 102], $duel->result());
        self::assertSame(4, BinaryInsertionDuel::start(range(1, 15), [100])->maxComparisons());
    }

    /**
     * @param array<int, float|int> $scores plus petit = préféré
     */
    private function play(BinaryInsertionDuel $duel, array $scores): BinaryInsertionDuel
    {
        while (null !== $pair = $duel->currentPair()) {
            // Aller-retour JSON à chaque question : la reprise doit être transparente.
            $duel = BinaryInsertionDuel::fromState(json_decode((string) json_encode($duel->toState()), true));
            $duel->answer($scores[$pair[0]] <= $scores[$pair[1]] ? $pair[0] : $pair[1]);
        }

        return $duel;
    }
}
