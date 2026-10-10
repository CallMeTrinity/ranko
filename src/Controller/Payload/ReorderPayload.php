<?php

namespace App\Controller\Payload;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ReorderPayload
{
    /**
     * @param list<int> $itemIds ids des éléments du catalogue, dans le nouvel ordre
     */
    public function __construct(
        #[Assert\Count(min: 1)]
        #[Assert\All([new Assert\Type('int')])]
        public array $itemIds,
    ) {
    }
}
