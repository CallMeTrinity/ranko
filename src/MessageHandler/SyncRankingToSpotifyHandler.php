<?php

namespace App\MessageHandler;

use App\Message\SyncRankingToSpotify;
use App\Spotify\SpotifyApiException;
use App\Spotify\SpotifyPlaylistSync;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

#[AsMessageHandler]
final class SyncRankingToSpotifyHandler
{
    public function __construct(
        private readonly SpotifyPlaylistSync $sync,
    ) {
    }

    public function __invoke(SyncRankingToSpotify $message): void
    {
        try {
            $this->sync->sync($message->rankingId);
        } catch (SpotifyApiException $exception) {
            // Seuls les échecs passagers arrivent ici. Après une 429, Spotify dit combien
            // attendre ; sinon, backoff du transport. forceRetry: false garde la limite
            // de max_retries du transport.
            throw new RecoverableMessageHandlingException($exception->getMessage(), 0, $exception, null === $exception->retryAfter ? null : $exception->retryAfter * 1000, forceRetry: false);
        }
    }
}
