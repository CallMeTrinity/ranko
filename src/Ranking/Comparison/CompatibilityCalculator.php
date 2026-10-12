<?php

namespace App\Ranking\Comparison;

use App\Entity\Ranking;
use App\Entity\User;
use App\Repository\RankingRepository;

final class CompatibilityCalculator
{
    public function __construct(
        private readonly RankingRepository $rankings,
        private readonly RankingComparator $comparator,
    ) {
    }

    /**
     * Compare les classements de l'utilisateur à ceux donnés, deux à deux quand ils
     * portent sur la même chose (même type, même périmètre). Les listes personnalisées
     * sont écartées : deux listes "Albums de l'été" n'ont en commun que le nom.
     *
     * @param list<Ranking> $theirs
     */
    public function between(User $user, array $theirs): Compatibility
    {
        $mineByTarget = [];
        foreach ($this->rankings->findByOwner($user) as $ranking) {
            if (!$ranking->isCustom()) {
                $mineByTarget[self::target($ranking)] = $ranking;
            }
        }

        $matches = [];
        foreach ($theirs as $ranking) {
            $mine = $ranking->isCustom() ? null : $mineByTarget[self::target($ranking)] ?? null;
            if (null !== $mine && $mine !== $ranking) {
                $matches[] = [$mine, $ranking];
            }
        }

        if ([] === $matches) {
            return new Compatibility([]);
        }

        // Charge les entrées des deux côtés en une requête : les classements déjà gérés
        // par l'EntityManager récupèrent leurs collections au passage.
        $ids = [];
        foreach ($matches as [$mine, $other]) {
            $ids[] = (int) $mine->getId();
            $ids[] = (int) $other->getId();
        }
        $this->rankings->findWithItemsByIds($ids);

        $pairs = [];
        foreach ($matches as [$mine, $other]) {
            $result = $this->comparator->compare($mine, $other)['result'];
            if ($result->isMeaningful()) {
                $pairs[] = ['mine' => $mine, 'theirs' => $other, 'result' => $result];
            }
        }

        return new Compatibility($pairs);
    }

    private static function target(Ranking $ranking): string
    {
        return $ranking->getItemKind()->value.':'.($ranking->getScope()?->getId() ?? '');
    }
}
