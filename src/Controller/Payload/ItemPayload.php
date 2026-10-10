<?php

namespace App\Controller\Payload;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ItemPayload
{
    public function __construct(
        #[Assert\Positive]
        public int $itemId,
    ) {
    }
}
