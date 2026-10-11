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
 * Le n°1 de chaque classement global public : l'artiste, l'album, le titre préférés.
 * Un nouveau type d'élément apparaît ici sans rien changer.
 */
#[AsTaggedItem(priority: 30)]
final class ShowcaseSection implements ProfileSection
{
    public function __construct(
        private readonly RankingRepository $rankings,
    ) {
    }

    public function build(ProfileContext $context): ?ProfileBlock
    {
        /** @var array<string, Ranking> $globals indexé par type, dans l'ordre de l'enum */
        $globals = [];
        foreach (CatalogItemKind::cases() as $kind) {
            foreach ($context->publicRankings as $ranking) {
                if ($ranking->getItemKind() === $kind && null === $ranking->getScope()) {
                    $globals[$kind->value] = $ranking;
                }
            }
        }

        if ([] === $globals) {
            return null;
        }

        $tops = $this->rankings->findTopItems(array_values($globals), 1);

        $favorites = [];
        foreach ($globals as $ranking) {
            $item = $tops[(int) $ranking->getId()][0] ?? null;
            if (null !== $item) {
                $favorites[] = ['kind' => $ranking->getItemKind(), 'item' => $item, 'ranking' => $ranking];
            }
        }

        return [] === $favorites ? null : new ProfileBlock('profile/section/_showcase.html.twig', [
            'favorites' => $favorites,
        ]);
    }
}
