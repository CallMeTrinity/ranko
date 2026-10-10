<?php

namespace App\Controller\Payload;

/**
 * La paire affichée voyage avec la réponse : le serveur ignore une réponse à une
 * question qui n'est plus la question en cours.
 */
final readonly class AnswerPayload
{
    public function __construct(
        public int $leftId,
        public int $rightId,
        public int $winnerId,
    ) {
    }
}
