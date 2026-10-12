<?php

namespace App\Controller\Payload;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ItemPayload
{
    public function __construct(
        #[Assert\Positive]
        public int $itemId,
        /** Position d'arrivée, commence à 1. Null pour ajouter à la fin. */
        #[Assert\Positive]
        public ?int $position = null,
    ) {
    }
}
