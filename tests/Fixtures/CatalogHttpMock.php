<?php

namespace App\Tests\Fixtures;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Remplace le transport HTTP en environnement de test (framework.http_client.mock_response_factory).
 * Les fixtures sont de vraies réponses MusicBrainz, Cover Art Archive, Deezer et Spotify, réduites.
 */
final class CatalogHttpMock
{
    public const string RADIOHEAD = 'a74b1b7f-71a5-4011-9441-d0b5e4122711';
    public const string OK_COMPUTER = 'b1392450-e666-3926-a536-22c65f834433';
    public const string OK_COMPUTER_FIRST_RELEASE = '1834eae1-741b-3c03-9ca5-0df3decb43ea';
    public const string KID_A = 'e75c0549-ad55-39e3-8025-c72c5d4a3c5d';
    /** Single de Muse dont les faces B sont réparties entre ses éditions (CD1, CD2, pressages étrangers). */
    public const string DEAD_STAR = 'ffc1785c-5ff0-3392-be18-a568469b7936';
    public const string UNKNOWN = '00000000-0000-0000-0000-000000000000';
    /** Recherche à laquelle MusicBrainz répond 503 "server busy". */
    public const string BUSY_QUERY = 'surcharge';
    /** Album dont la pochette répond 500, comme un miroir archive.org en panne. */
    public const string BROKEN_COVER = '00000000-0000-0000-0000-000000000500';

    /** Titre Deezer qui correspond à "Airbag" de Radiohead dans deezer/search-airbag.json. */
    public const string DEEZER_AIRBAG = '138539971';

    /** ISRC d'"Airbag" dans musicbrainz/release-ok-computer.json. */
    public const string AIRBAG_ISRC = 'GBAYE9700136';
    /**
     * spotify/search-airbag.json sert à toutes les recherches Spotify. Par ISRC, on prend le
     * premier résultat de Radiohead : le remaster. Par texte, le titre exact du même album.
     */
    public const string SPOTIFY_AIRBAG_BY_ISRC = 'spotify:track:fixtureAirbagRemaster00';
    public const string SPOTIFY_AIRBAG_BY_TEXT = 'spotify:track:fixtureAirbag000000000';
    public const string SPOTIFY_PLAYLIST = 'fixturePlaylist0000000';
    /** Compte de spotify/me.json. */
    public const string SPOTIFY_USER = 'alice-spotify';
    /** Code OAuth d'un compte absent de la liste blanche de l'app : Spotify donne un token, puis répond 403. */
    public const string SPOTIFY_CODE_NOT_ALLOWLISTED = 'not-allowlisted';
    public const string SPOTIFY_TOKEN_FORBIDDEN = 'forbidden-token';
    /** Token obtenu sans tous les scopes (connexion d'avant un ajout de scope) : 403. */
    public const string SPOTIFY_TOKEN_MISSING_SCOPE = 'missing-scope-token';
    /** Token avec lequel Spotify répond 429. */
    public const string SPOTIFY_TOKEN_RATE_LIMITED = 'rate-limited-token';
    /** Playlist supprimée côté Spotify : 404. */
    public const string SPOTIFY_DELETED_PLAYLIST = 'deletedPlaylist';

    private const array ROUTES = [
        'musicbrainz.org/ws/2/artist?' => 'artist-search.json',
        'musicbrainz.org/ws/2/artist/'.self::RADIOHEAD => 'artist.json',
        'musicbrainz.org/ws/2/release-group?' => 'release-groups.json',
        'musicbrainz.org/ws/2/release-group/'.self::OK_COMPUTER => 'release-group-ok-computer.json',
        'musicbrainz.org/ws/2/release-group/'.self::DEAD_STAR => 'release-group-dead-star.json',
        'musicbrainz.org/ws/2/release/'.self::OK_COMPUTER_FIRST_RELEASE => 'release-ok-computer.json',
        'musicbrainz.org/ws/2/release?release-group='.self::DEAD_STAR => 'releases-dead-star.json',
        'coverartarchive.org/release-group/'.self::OK_COMPUTER => 'cover-ok-computer.json',
        'api.deezer.com/search?' => '../deezer/search-airbag.json',
        'api.deezer.com/track/'.self::DEEZER_AIRBAG => '../deezer/track-airbag.json',
        'accounts.spotify.com/api/token' => '../spotify/token.json',
        // Avant "me" : on compare des préfixes.
        'api.spotify.com/v1/me/playlists' => '../spotify/playlist.json',
        'api.spotify.com/v1/me' => '../spotify/me.json',
        'api.spotify.com/v1/search?' => '../spotify/search-airbag.json',
        'api.spotify.com/v1/playlists/' => '../spotify/snapshot.json',
    ];

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options = []): MockResponse
    {
        if (null !== $response = $this->spotify($url, $options)) {
            return $response;
        }

        if (str_contains($url, 'query='.self::BUSY_QUERY)) {
            return new MockResponse('{"error": "The MusicBrainz web server is currently busy."}', ['http_code' => 503]);
        }

        if (str_contains($url, self::BROKEN_COVER)) {
            return new MockResponse('Internal Server Error', ['http_code' => 500]);
        }

        // Fichiers image des pochettes (les URLs listées dans cover-ok-computer.json).
        if (str_starts_with($url, 'https://coverartarchive.org/release/')) {
            return new MockResponse(
                (string) file_get_contents(__DIR__.'/musicbrainz/cover.jpg'),
                ['response_headers' => ['content-type' => 'image/jpeg']],
            );
        }

        foreach (self::ROUTES as $prefix => $fixture) {
            if (str_starts_with(substr($url, strlen('https://')), $prefix)) {
                return new MockResponse(
                    (string) file_get_contents(__DIR__.'/musicbrainz/'.$fixture),
                    ['response_headers' => ['content-type' => 'application/json']],
                );
            }
        }

        return new MockResponse('{"error": "Not Found"}', [
            'http_code' => 404,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    /**
     * Cas d'erreur de Spotify, déclenchés par le code OAuth, le token ou la playlist.
     *
     * @param array<string, mixed> $options
     */
    private function spotify(string $url, array $options): ?MockResponse
    {
        $json = static fn (string $body, int $code, array $headers = []): MockResponse => new MockResponse($body, [
            'http_code' => $code,
            'response_headers' => ['content-type' => 'application/json', ...$headers],
        ]);

        if (str_starts_with($url, 'https://accounts.spotify.com/api/token') && str_contains((string) ($options['body'] ?? ''), 'code='.self::SPOTIFY_CODE_NOT_ALLOWLISTED)) {
            return $json(json_encode(['access_token' => self::SPOTIFY_TOKEN_FORBIDDEN, 'expires_in' => 3600, 'refresh_token' => 'refresh'], \JSON_THROW_ON_ERROR), 200);
        }

        if (!str_starts_with($url, 'https://api.spotify.com/')) {
            return null;
        }

        $authorization = implode(' ', $options['normalized_headers']['authorization'] ?? []);

        return match (true) {
            str_contains($authorization, self::SPOTIFY_TOKEN_FORBIDDEN) => $json('{"error": {"status": 403, "message": "Check settings on developer.spotify.com/dashboard, the user may not be registered."}}', 403),
            str_contains($authorization, self::SPOTIFY_TOKEN_MISSING_SCOPE) => $json('{"error": {"status": 403, "message": "Insufficient client scope"}}', 403),
            str_contains($authorization, self::SPOTIFY_TOKEN_RATE_LIMITED) => $json('{"error": {"status": 429, "message": "API rate limit exceeded"}}', 429, ['retry-after' => '30']),
            str_contains($url, '/playlists/'.self::SPOTIFY_DELETED_PLAYLIST.'/') => $json('{"error": {"status": 404, "message": "Resource not found"}}', 404),
            default => null,
        };
    }
}
