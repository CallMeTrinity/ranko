<?php

namespace App\Profile;

/**
 * Section prête à rendre : un partial et ses variables, rien d'autre.
 */
final readonly class ProfileBlock
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $template,
        public array $context = [],
    ) {
    }
}
