<?php

namespace App\Catalog\Exception;

final class ItemNotFoundException extends \RuntimeException
{
    public static function forId(string $type, string $externalId, ?\Throwable $previous = null): self
    {
        return new self(sprintf('Aucun élément "%s" avec l\'identifiant externe "%s".', $type, $externalId), 0, $previous);
    }
}
