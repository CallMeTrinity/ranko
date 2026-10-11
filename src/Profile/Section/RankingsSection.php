<?php

namespace App\Profile\Section;

use App\Entity\Ranking;
use App\Enum\CatalogItemKind;
use App\Profile\ProfileBlock;
use App\Profile\ProfileContext;
use App\Profile\ProfileSection;
use App\Repository\RankingRepository;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Classements publics, filtrables par type. Toujours affichée, même vide.
 */
#[AsTaggedItem(priority: 10)]
final class RankingsSection implements ProfileSection
{
    public function __construct(
        private readonly RankingRepository $rankings,
    ) {
    }

    public function build(ProfileContext $context): ProfileBlock
    {
        $all = $context->publicRankings;
        $rankings = null === $context->kind
            ? $all
            : array_values(array_filter($all, static fn (Ranking $ranking): bool => $ranking->getItemKind() === $context->kind));

        // Onglets : seulement les types que le profil a classés, dans l'ordre de l'enum.
        $present = array_map(static fn (Ranking $ranking): CatalogItemKind => $ranking->getItemKind(), $all);
        $kinds = array_values(array_filter(CatalogItemKind::cases(), static fn (CatalogItemKind $kind): bool => \in_array($kind, $present, true)));

        return new ProfileBlock('profile/section/_rankings.html.twig', [
            'profile' => $context->profile,
            'is_me' => $context->isOwnProfile(),
            'kind' => $context->kind,
            'kinds' => $kinds,
            'rankings' => $rankings,
            'tops' => $this->rankings->findTopItems($rankings),
        ]);
    }
}
