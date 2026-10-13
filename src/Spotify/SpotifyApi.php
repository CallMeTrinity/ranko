<?php

namespace App\Spotify;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Web API Spotify (https://developer.spotify.com/documentation/web-api), limitée à ce
 * dont la synchro a besoin.
 *
 * Attention aux tutos d'avant février 2026 : les endpoints /playlists/{id}/tracks et
 * /users/{id}/playlists ont disparu au profit de /playlists/{id}/items et /me/playlists,
 * et la recherche renvoie 10 résultats au plus.
 */
final class SpotifyApi
{
    /**
     * Modifier les playlists de l'utilisateur, privées comme publiques (s'il en rend une
     * publique). user-read-private : la recherche avec market=from_token lit le pays du
     * compte, sans lui Spotify répond 403 "Insufficient client scope".
     */
    private const string SCOPES = 'playlist-modify-private playlist-modify-public user-read-private';

    /** Titres par requête d'ajout à une playlist, imposé par Spotify. */
    private const int ITEMS_PER_REQUEST = 100;

    public const int SEARCH_LIMIT = 10;

    public function __construct(
        #[Target('spotify_accounts.client')]
        private readonly HttpClientInterface $accounts,
        #[Target('spotify.client')]
        private readonly HttpClientInterface $api,
        #[Autowire('%env(SPOTIFY_CLIENT_ID)%')]
        private readonly string $clientId,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->clientId;
    }

    /**
     * Page de consentement Spotify. Elle renvoie ensuite vers $redirectUri, qui doit être
     * déclarée à l'identique dans le dashboard Spotify.
     */
    public function authorizeUrl(string $redirectUri, string $state): string
    {
        return 'https://accounts.spotify.com/authorize?'.http_build_query([
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'scope' => self::SCOPES,
            'state' => $state,
        ], '', '&', \PHP_QUERY_RFC3986);
    }

    /**
     * @throws SpotifyApiException
     */
    public function exchangeCode(string $code, string $redirectUri): SpotifyTokens
    {
        return $this->tokens([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);
    }

    /**
     * @throws SpotifyApiException 400 si l'utilisateur a révoqué l'accès depuis son compte Spotify
     */
    public function refreshTokens(string $refreshToken): SpotifyTokens
    {
        return $this->tokens([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * @return array{id: string, display_name: ?string}
     *
     * @throws SpotifyApiException 403 si le compte n'est pas dans la liste blanche de l'app (Development Mode)
     */
    public function me(string $accessToken): array
    {
        $data = $this->request($this->api, 'GET', 'me', ['auth_bearer' => $accessToken]);

        return [
            'id' => (string) ($data['id'] ?? throw new SpotifyApiException('Réponse de Spotify sans identifiant de compte.')),
            'display_name' => ($data['display_name'] ?? '') ?: null,
        ];
    }

    /**
     * Titres disponibles dans le pays de l'utilisateur.
     *
     * @return list<array<string, mixed>>
     *
     * @throws SpotifyApiException
     */
    public function searchTracks(string $accessToken, string $query): array
    {
        $data = $this->request($this->api, 'GET', 'search', [
            'auth_bearer' => $accessToken,
            'query' => ['q' => $query, 'type' => 'track', 'limit' => self::SEARCH_LIMIT, 'market' => 'from_token'],
        ]);

        return array_values($data['tracks']['items'] ?? []);
    }

    /**
     * Playlist privée, dans le compte de l'utilisateur.
     *
     * @return array{id: string, url: ?string}
     *
     * @throws SpotifyApiException
     */
    public function createPlaylist(string $accessToken, string $name, string $description): array
    {
        $data = $this->request($this->api, 'POST', 'me/playlists', [
            'auth_bearer' => $accessToken,
            'json' => ['name' => $name, 'description' => $description, 'public' => false],
        ]);

        return [
            'id' => (string) ($data['id'] ?? throw new SpotifyApiException('Réponse de Spotify sans identifiant de playlist.')),
            'url' => $data['external_urls']['spotify'] ?? null,
        ];
    }

    /**
     * Remplace tout le contenu de la playlist. Spotify accepte 100 titres par requête :
     * le premier lot remplace, les suivants s'ajoutent à la suite.
     *
     * @param list<string> $uris
     *
     * @return string snapshot_id, la version de la playlist après modification
     *
     * @throws SpotifyApiException 404 si la playlist n'existe plus
     */
    public function replacePlaylistItems(string $accessToken, string $playlistId, array $uris): string
    {
        $snapshotId = '';

        foreach (array_chunk($uris, self::ITEMS_PER_REQUEST) ?: [[]] as $index => $chunk) {
            $data = $this->request($this->api, 0 === $index ? 'PUT' : 'POST', sprintf('playlists/%s/items', rawurlencode($playlistId)), [
                'auth_bearer' => $accessToken,
                'json' => ['uris' => $chunk],
            ]);
            $snapshotId = (string) ($data['snapshot_id'] ?? $snapshotId);
        }

        return $snapshotId;
    }

    /**
     * @param array<string, string> $body
     *
     * @throws SpotifyApiException
     */
    private function tokens(array $body): SpotifyTokens
    {
        $data = $this->request($this->accounts, 'POST', 'api/token', ['body' => $body]);

        return new SpotifyTokens(
            accessToken: (string) ($data['access_token'] ?? throw new SpotifyApiException('Réponse de Spotify sans token.')),
            refreshToken: ($data['refresh_token'] ?? '') ?: null,
            expiresIn: (int) ($data['expires_in'] ?? 3600),
        );
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     *
     * @throws SpotifyApiException
     */
    private function request(HttpClientInterface $client, string $method, string $path, array $options): array
    {
        try {
            $response = $client->request($method, $path, $options);
            $status = $response->getStatusCode();

            try {
                $data = $response->toArray(false);
            } catch (DecodingExceptionInterface) {
                $data = [];
            }

            if ($status >= 400) {
                $retryAfter = $response->getHeaders(false)['retry-after'][0] ?? null;

                throw new SpotifyApiException(sprintf('Spotify a répondu %d : %s', $status, $this->errorMessage($data)), $status, null === $retryAfter ? null : (int) $retryAfter);
            }

            return $data;
        } catch (HttpClientExceptionInterface $exception) {
            throw new SpotifyApiException('Spotify ne répond pas : '.$exception->getMessage(), previous: $exception);
        }
    }

    /**
     * Le serveur OAuth répond {"error": "invalid_grant", "error_description": "…"},
     * la Web API {"error": {"status": 403, "message": "…"}}.
     *
     * @param array<string, mixed> $data
     */
    private function errorMessage(array $data): string
    {
        $error = $data['error'] ?? null;

        return (string) (\is_array($error) ? ($error['message'] ?? '') : ($data['error_description'] ?? $error ?? ''));
    }
}
