<?php

namespace App\Controller\Payload;

use App\Enum\CatalogItemKind;

final readonly class CreateRankingPayload
{
    public function __construct(
        public CatalogItemKind $kind,
        public ?int $scopeId = null,
    ) {
    }
}
