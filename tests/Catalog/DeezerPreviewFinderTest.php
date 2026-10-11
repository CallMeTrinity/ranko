<?php

namespace App\Tests\Catalog;

use App\Catalog\Deezer\DeezerPreviewFinder;
use App\Entity\Album;
use App\Entity\Artist;
use App\Entity\CatalogItem;
use App\Entity\Track;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DeezerPreviewFinderTest extends TestCase
{
    private const string PREVIEW = 'https://cdnt-preview.dzcdn.net/api/1/1/fixture/138539971.mp3?hdnea=exp=0';

    /**
     * Objet et non tableau : rempli par le client HTTP simulé, dans le dos de PHPStan,
     * qui croirait sinon le compte figé entre deux assertions.
     *
     * @var \ArrayObject<int, string>
     */
    private \ArrayObject $requestedUrls;

    protected function setUp(): void
    {
        $this->requestedUrls = new \ArrayObject();
    }

    public function testFindsTheTrackOfTheSameArtistAndCachesIt(): void
    {
        $finder = $this->finder();
        $track = $this->track('Radiohead', 'OK Computer', 'Airbag');

        self::assertSame(self::PREVIEW, $finder->findPreviewUrl($track));
        self::assertCount(2, $this->requestedUrls, 'Une recherche puis l\'extrait frais');
        [$search, $preview] = $this->requestedUrls->getArrayCopy();
        self::assertStringContainsString('q=Radiohead%20Airbag%20OK%20Computer', $search);
        self::assertStringEndsWith('/track/138539971', $preview);

        self::assertSame(self::PREVIEW, $finder->findPreviewUrl($track));
        self::assertCount(2, $this->requestedUrls, 'La correspondance et l\'extrait sont en cache');
    }

    public function testIgnoresCoversAndRemembersThatThereIsNoMatch(): void
    {
        $finder = $this->finder();
        // Les résultats contiennent "Airbag" par d'autres artistes : ce sont des reprises.
        $track = $this->track('Artiste inconnu', 'OK Computer', 'Airbag');

        self::assertNull($finder->findPreviewUrl($track));
        self::assertCount(2, $this->requestedUrls, 'Avec puis sans le nom de l\'album');

        self::assertNull($finder->findPreviewUrl($track));
        self::assertCount(2, $this->requestedUrls, 'L\'absence de correspondance est en cache');
    }

    public function testDoesNotCacheADeezerError(): void
    {
        $finder = $this->finder(new MockResponse('{"error": {"type": "Exception", "message": "Quota limit exceeded", "code": 4}}'));
        $track = $this->track('Radiohead', 'OK Computer', 'Airbag');

        self::assertNull($finder->findPreviewUrl($track));
        self::assertNull($finder->findPreviewUrl($track));
        self::assertCount(2, $this->requestedUrls, 'Chaque écoute retente sa chance');
    }

    private function finder(?MockResponse $response = null): DeezerPreviewFinder
    {
        $httpClient = new MockHttpClient(function (string $method, string $url) use ($response): MockResponse {
            $this->requestedUrls[] = $url;

            return $response ?? new MockResponse((string) file_get_contents(__DIR__.'/../Fixtures/deezer/'.(str_contains($url, '/track/') ? 'track-airbag.json' : 'search-airbag.json')));
        }, 'https://api.deezer.com/');

        return new DeezerPreviewFinder($httpClient, new ArrayAdapter());
    }

    private function track(string $artist, string $album, string $name): Track
    {
        $track = new Track(new Album(new Artist($artist), $album), $name);
        // Clé de cache : un titre non persisté n'a pas d'id.
        (new \ReflectionProperty(CatalogItem::class, 'id'))->setValue($track, random_int(1, \PHP_INT_MAX));

        return $track;
    }
}
