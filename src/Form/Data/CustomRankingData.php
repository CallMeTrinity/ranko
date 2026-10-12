<?php

namespace App\Form\Data;

use App\Entity\Ranking;
use App\Enum\CatalogItemKind;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Saisie du formulaire de création d'une liste personnalisée. Pas readonly : le
 * composant Form écrit dans les propriétés.
 */
final class CustomRankingData
{
    public const int MAX_SIZE = 100;

    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: Ranking::NAME_MAX_LENGTH)]
    public ?string $name = null;

    #[Assert\NotNull]
    public ?CatalogItemKind $kind = null;

    /** Null pour une liste sans limite. */
    #[Assert\Range(min: 1, max: self::MAX_SIZE)]
    public ?int $maxSize = 10;
}
