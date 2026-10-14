<?php

namespace App\Form\Data;

use App\Entity\Ranking;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Saisie du formulaire de renommage d'une liste personnalisée. Pas readonly : le
 * composant Form écrit dans les propriétés.
 */
final class RenameRankingData
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: Ranking::NAME_MAX_LENGTH)]
        public ?string $name = null,
    ) {
    }
}
