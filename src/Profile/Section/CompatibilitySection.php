<?php

namespace App\Profile\Section;

use App\Profile\ProfileBlock;
use App\Profile\ProfileContext;
use App\Profile\ProfileSection;
use App\Ranking\Comparison\CompatibilityCalculator;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Accord entre le visiteur connecté et le profil, sur leurs classements communs.
 */
#[AsTaggedItem(priority: 20)]
final class CompatibilitySection implements ProfileSection
{
    public function __construct(
        private readonly CompatibilityCalculator $calculator,
    ) {
    }

    public function build(ProfileContext $context): ?ProfileBlock
    {
        if (null === $context->viewer || $context->isOwnProfile()) {
            return null;
        }

        $compatibility = $this->calculator->between($context->viewer, $context->publicRankings);

        return $compatibility->isMeaningful() ? new ProfileBlock('profile/section/_compatibility.html.twig', [
            'compatibility' => $compatibility,
            'profile' => $context->profile,
        ]) : null;
    }
}
