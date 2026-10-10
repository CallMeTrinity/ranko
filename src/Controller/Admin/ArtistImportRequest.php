<?php

namespace App\Controller\Admin;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ArtistImportRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $externalId = '',
    ) {
    }
}
