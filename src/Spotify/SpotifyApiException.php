<?php

namespace App\Spotify;

final class SpotifyApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        /** Code HTTP, 0 si Spotify n'a pas répondu (réseau, timeout). */
        public readonly int $statusCode = 0,
        /** Secondes à attendre, données par Spotify avec une 429. */
        public readonly ?int $retryAfter = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    /**
     * Le token a été obtenu sans un scope dont l'appel a besoin : il faut reconnecter le
     * compte pour que Spotify redemande l'autorisation.
     */
    public function isMissingScope(): bool
    {
        return 403 === $this->statusCode && str_contains($this->getMessage(), 'Insufficient client scope');
    }

    /**
     * Trop de requêtes, panne côté Spotify ou réseau : ça passera plus tard. Le reste
     * (token révoqué, compte pas autorisé sur l'app) demande une action humaine.
     */
    public function isRetryable(): bool
    {
        return 0 === $this->statusCode || 429 === $this->statusCode || $this->statusCode >= 500;
    }
}
