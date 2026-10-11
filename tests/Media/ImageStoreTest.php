<?php

namespace App\Tests\Media;

use App\Media\ImageStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ImageStoreTest extends TestCase
{
    private const string URL = 'https://images.example/release/42/front-1200.JPG';

    private string $mediaDir;

    protected function setUp(): void
    {
        $this->mediaDir = sys_get_temp_dir().'/ranko-media-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->mediaDir);
    }

    public function testStoresTheImageOnceUnderANameDerivedFromItsUrl(): void
    {
        $image = (string) file_get_contents(__DIR__.'/../Fixtures/musicbrainz/cover.jpg');
        $httpClient = new MockHttpClient([new MockResponse($image)]);
        $store = new ImageStore($httpClient, new Filesystem(), $this->mediaDir);

        $path = $store->store(self::URL, 'covers');

        self::assertSame('covers/'.hash('xxh128', self::URL).'.jpg', $path);
        self::assertSame($image, file_get_contents($this->mediaDir.'/'.$path));

        self::assertSame($path, $store->store(self::URL, 'covers'));
        self::assertSame(1, $httpClient->getRequestsCount(), 'Une image déjà copiée n\'est pas retéléchargée');
    }

    public function testReturnsNullWhenTheResponseIsAnErrorOrNotAnImage(): void
    {
        $store = new ImageStore(new MockHttpClient([
            new MockResponse('Internal Server Error', ['http_code' => 500]),
            new MockResponse('<html>Mirror down</html>'),
        ]), new Filesystem(), $this->mediaDir);

        self::assertNull($store->store(self::URL, 'covers'));
        self::assertNull($store->store(self::URL, 'covers'));
        self::assertDirectoryDoesNotExist($this->mediaDir);
    }
}
