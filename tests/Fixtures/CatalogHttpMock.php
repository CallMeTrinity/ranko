<?php

namespace App\Tests\Fixtures;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Remplace le transport HTTP en environnement de test (framework.http_client.mock_response_factory).
 * Les fixtures sont de vraies réponses MusicBrainz et Cover Art Archive, réduites.
 */
final class CatalogHttpMock
{
    public const string RADIOHEAD = 'a74b1b7f-71a5-4011-9441-d0b5e4122711';
    public const string OK_COMPUTER = 'b1392450-e666-3926-a536-22c65f834433';
    public const string OK_COMPUTER_FIRST_RELEASE = '1834eae1-741b-3c03-9ca5-0df3decb43ea';
    public const string KID_A = 'e75c0549-ad55-39e3-8025-c72c5d4a3c5d';
    public const string UNKNOWN = '00000000-0000-0000-0000-000000000000';
    /** Recherche à laquelle MusicBrainz répond 503 "server busy". */
    public const string BUSY_QUERY = 'surcharge';
    /** Album dont la pochette répond 500, comme un miroir archive.org en panne. */
    public const string BROKEN_COVER = '00000000-0000-0000-0000-000000000500';

    private const array ROUTES = [
        'musicbrainz.org/ws/2/artist?' => 'artist-search.json',
        'musicbrainz.org/ws/2/artist/'.self::RADIOHEAD => 'artist.json',
        'musicbrainz.org/ws/2/release-group?' => 'release-groups.json',
        'musicbrainz.org/ws/2/release-group/'.self::OK_COMPUTER => 'release-group-ok-computer.json',
        'musicbrainz.org/ws/2/release/'.self::OK_COMPUTER_FIRST_RELEASE => 'release-ok-computer.json',
        'coverartarchive.org/release-group/'.self::OK_COMPUTER => 'cover-ok-computer.json',
    ];

    public function __invoke(string $method, string $url): MockResponse
    {
        if (str_contains($url, 'query='.self::BUSY_QUERY)) {
            return new MockResponse('{"error": "The MusicBrainz web server is currently busy."}', ['http_code' => 503]);
        }

        if (str_contains($url, self::BROKEN_COVER)) {
            return new MockResponse('Internal Server Error', ['http_code' => 500]);
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
}
