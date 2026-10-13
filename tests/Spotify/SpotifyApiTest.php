<?php

namespace App\Tests\Spotify;

use App\Spotify\SpotifyApi;
use App\Spotify\SpotifyApiException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SpotifyApiTest extends TestCase
{
    /**
     * Objet et non tableau : rempli par le client HTTP simulé, dans le dos de PHPStan.
     *
     * @var \ArrayObject<int, array{method: string, url: string, body: string}>
     */
    private \ArrayObject $requests;

    protected function setUp(): void
    {
        $this->requests = new \ArrayObject();
    }

    public function testAuthorizeUrlAsksForPlaylistScopes(): void
    {
        $url = $this->api()->authorizeUrl('http://127.0.0.1:8000/spotify/callback', 'state123');

        self::assertStringStartsWith('https://accounts.spotify.com/authorize?', $url);
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        self::assertSame([
            'client_id' => 'client-id',
            'response_type' => 'code',
            'redirect_uri' => 'http://127.0.0.1:8000/spotify/callback',
            'scope' => 'playlist-modify-private playlist-modify-public user-read-private',
            'state' => 'state123',
        ], $query);
    }

    public function testReplacesItemsByBatchesOfOneHundred(): void
    {
        $uris = array_map(static fn (int $i): string => 'spotify:track:'.$i, range(1, 150));

        $snapshotId = $this->api(
            new MockResponse('{"snapshot_id": "first"}', ['http_code' => 200]),
            new MockResponse('{"snapshot_id": "second"}', ['http_code' => 201]),
        )->replacePlaylistItems('token', 'playlist1', $uris);

        self::assertSame('second', $snapshotId, 'Version de la playlist après le dernier lot');
        self::assertCount(2, $this->requests);
        [$replace, $append] = $this->requests->getArrayCopy();
        self::assertSame(['PUT', 'https://api.spotify.com/v1/playlists/playlist1/items'], [$replace['method'], $replace['url']]);
        self::assertSame(\array_slice($uris, 0, 100), json_decode($replace['body'], true)['uris']);
        self::assertSame(['POST', 'https://api.spotify.com/v1/playlists/playlist1/items'], [$append['method'], $append['url']]);
        self::assertSame(\array_slice($uris, 100), json_decode($append['body'], true)['uris']);
    }

    public function testEmptyingThePlaylistStillSendsOneRequest(): void
    {
        $this->api(new MockResponse('{"snapshot_id": "empty"}'))->replacePlaylistItems('token', 'playlist1', []);

        self::assertCount(1, $this->requests);
        [$replace] = $this->requests->getArrayCopy();
        self::assertSame(['uris' => []], json_decode($replace['body'], true));
    }

    public function testRateLimitCarriesTheDelay(): void
    {
        $api = $this->api(new MockResponse('{"error": {"status": 429, "message": "API rate limit exceeded"}}', [
            'http_code' => 429,
            'response_headers' => ['retry-after' => '12'],
        ]));

        try {
            $api->searchTracks('token', 'Airbag');
            self::fail('Une 429 doit lever une exception');
        } catch (SpotifyApiException $exception) {
            self::assertSame(429, $exception->statusCode);
            self::assertSame(12, $exception->retryAfter);
            self::assertTrue($exception->isRetryable());
            self::assertStringContainsString('API rate limit exceeded', $exception->getMessage());
        }
    }

    public function testRevokedRefreshTokenIsNotRetryable(): void
    {
        $api = $this->api(new MockResponse('{"error": "invalid_grant", "error_description": "Refresh token revoked"}', ['http_code' => 400]));

        try {
            $api->refreshTokens('revoked');
            self::fail('Un refus doit lever une exception');
        } catch (SpotifyApiException $exception) {
            self::assertFalse($exception->isRetryable());
            self::assertStringContainsString('Refresh token revoked', $exception->getMessage());
        }
    }

    private function api(MockResponse ...$responses): SpotifyApi
    {
        $responses = array_values($responses);
        $requests = $this->requests;
        $factory = static function (string $method, string $url, array $options) use (&$responses, $requests): MockResponse {
            $requests[] = ['method' => $method, 'url' => $url, 'body' => (string) ($options['body'] ?? '')];

            return array_shift($responses) ?? new MockResponse('{}');
        };

        return new SpotifyApi(
            new MockHttpClient($factory, 'https://accounts.spotify.com/'),
            new MockHttpClient($factory, 'https://api.spotify.com/v1/'),
            'client-id',
        );
    }
}
