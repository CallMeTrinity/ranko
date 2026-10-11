<?php

namespace App\Profile;

use App\Entity\Ranking;
use App\Entity\User;
use App\Enum\CatalogItemKind;

/**
 * Ce que toutes les sections partagent, chargé une seule fois par page.
 */
final readonly class ProfileContext
{
    /**
     * @param list<Ranking> $publicRankings classements publics non vides, du plus récent au plus ancien
     */
    public function __construct(
        public User $profile,
        public ?User $viewer,
        public array $publicRankings,
        public ?CatalogItemKind $kind = null,
    ) {
    }

    public function isOwnProfile(): bool
    {
        return null !== $this->viewer && $this->viewer->getId() === $this->profile->getId();
    }
}
