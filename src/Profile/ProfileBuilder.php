<?php

namespace App\Profile;

use App\Entity\User;
use App\Enum\CatalogItemKind;
use App\Repository\RankingRepository;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class ProfileBuilder
{
    /**
     * @param iterable<ProfileSection> $sections
     */
    public function __construct(
        private readonly RankingRepository $rankings,
        #[AutowireIterator(ProfileSection::class)]
        private readonly iterable $sections,
    ) {
    }

    /**
     * @return list<ProfileBlock>
     */
    public function build(User $profile, ?User $viewer, ?CatalogItemKind $kind = null): array
    {
        $context = new ProfileContext($profile, $viewer, $this->rankings->findPublicByOwner($profile), $kind);

        $blocks = [];
        foreach ($this->sections as $section) {
            $block = $section->build($context);
            if (null !== $block) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }
}
