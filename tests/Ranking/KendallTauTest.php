<?php

namespace App\Tests\Ranking;

use App\Ranking\Comparison\KendallTau;
use PHPUnit\Framework\TestCase;

final class KendallTauTest extends TestCase
{
    public function testIdenticalOrders(): void
    {
        $result = KendallTau::compare([1, 2, 3, 4], [1, 2, 3, 4]);

        self::assertSame(1.0, $result->tau());
        self::assertSame(1.0, $result->agreement());
    }

    public function testReversedOrders(): void
    {
        $result = KendallTau::compare([1, 2, 3, 4], [4, 3, 2, 1]);

        self::assertSame(-1.0, $result->tau());
        self::assertSame(0.0, $result->agreement());
    }

    public function testOneSwapOutOfSixPairs(): void
    {
        // Seule la paire {3, 4} est inversée : C = 5, D = 1, τ = 4/6.
        $result = KendallTau::compare([1, 2, 3, 4], [1, 2, 4, 3]);

        self::assertSame(5, $result->concordant);
        self::assertSame(1, $result->discordant);
        self::assertEqualsWithDelta(2 / 3, $result->tau(), 1e-9);
        self::assertEqualsWithDelta(5 / 6, $result->agreement(), 1e-9);
    }

    public function testOnlyCommonItemsCount(): void
    {
        // Communs : 2, 3, 5. Ordre 2 > 3 > 5 d'un côté, 5 > 2 > 3 de l'autre.
        $result = KendallTau::compare([1, 2, 3, 4, 5], [5, 9, 2, 8, 3]);

        self::assertSame([2, 3, 5], $result->commonIds);
        self::assertSame(1, $result->concordant);
        self::assertSame(2, $result->discordant);
    }

    public function testNotMeaningfulWithLessThanTwoCommonItems(): void
    {
        $result = KendallTau::compare([1, 2], [2, 3]);

        self::assertFalse($result->isMeaningful());
        self::assertNull($result->tau());
        self::assertNull($result->agreement());
    }
}
