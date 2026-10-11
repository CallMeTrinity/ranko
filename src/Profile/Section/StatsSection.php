<?php

namespace App\Profile\Section;

use App\Profile\ProfileBlock;
use App\Profile\ProfileContext;
use App\Profile\ProfileSection;
use App\Repository\RankingRepository;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: 40)]
final class StatsSection implements ProfileSection
{
    public function __construct(
        private readonly RankingRepository $rankings,
    ) {
    }

    public function build(ProfileContext $context): ?ProfileBlock
    {
        $rankings = $context->publicRankings;
        if ([] === $rankings) {
            return null;
        }

        return new ProfileBlock('profile/section/_stats.html.twig', [
            'ranking_count' => \count($rankings),
            'item_count' => $this->rankings->countDistinctItems($rankings),
            // Déjà triés du plus récemment modifié au plus ancien.
            'last_activity' => $rankings[0]->getUpdatedAt(),
        ]);
    }
}
