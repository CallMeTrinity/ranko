<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Album;
use App\Entity\Artist;
use App\Message\ImportAlbumTracks;
use App\Message\ImportArtistDiscography;
use App\Tests\Fixtures\CatalogHttpMock;
use App\Tests\Support\CreatesEntities;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class BackOfficeTest extends WebTestCase
{
    use CreatesEntities;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Garde le même conteneur entre les requêtes : on lit ensuite le transport en mémoire.
        $this->client->disableReboot();
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/admin');

        self::assertResponseRedirects('/login');
    }

    public function testRegularUserIsForbidden(): void
    {
        $this->client->loginUser(self::createUser());
        $this->client->request('GET', '/admin/artist');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminSeesCatalogPages(): void
    {
        $this->client->loginUser(self::createUser('admin', admin: true));
        $artist = self::createArtist();
        self::createAlbum($artist, trackNames: ['Airbag']);

        foreach (['/admin/artist', '/admin/album', '/admin/track'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }
    }

    public function testSearchThenImportArtist(): void
    {
        $this->client->loginUser(self::createUser('admin', admin: true));

        $crawler = $this->client->request('GET', '/admin/import?q=radiohead');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Radiohead');

        $form = $crawler->filter('form[action$="/admin/import/start"]')->first()->form();
        self::assertSame(CatalogHttpMock::RADIOHEAD, $form->getValues()['externalId']);
        $this->client->submit($form);

        self::assertResponseRedirects();
        self::assertNotNull(self::em()->getRepository(Artist::class)->findOneBy(['name' => 'Radiohead']));
        self::assertInstanceOf(ImportArtistDiscography::class, $this->sentMessages()[0] ?? null);

        // L'artiste est maintenant signalé comme déjà importé.
        $this->client->request('GET', '/admin/import?q=radiohead');
        self::assertSelectorTextContains('table', 'Déjà importé');
    }

    public function testSearchShowsAMessageWhenMusicBrainzIsBusy(): void
    {
        $this->client->loginUser(self::createUser('admin', admin: true));

        $this->client->request('GET', '/admin/import?q='.CatalogHttpMock::BUSY_QUERY);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'MusicBrainz ne répond pas');
    }

    public function testImportRejectsInvalidCsrfToken(): void
    {
        $this->client->loginUser(self::createUser('admin', admin: true));

        $this->client->request('POST', '/admin/import/start', ['externalId' => CatalogHttpMock::RADIOHEAD, '_token' => 'invalide']);

        // InvalidCsrfTokenException est une AuthenticationException : le firewall
        // renvoie vers la connexion au lieu d'un 403. L'important : rien n'est importé.
        self::assertResponseRedirects('/login');
        self::assertSame([], $this->sentMessages());
    }

    public function testReimportAction(): void
    {
        $this->client->loginUser(self::createUser('admin', admin: true));
        $artist = self::createArtist();

        $crawler = $this->client->request('GET', '/admin/artist');
        $this->client->submit($crawler->filter('form[action*="/reimport"]')->first()->form());

        self::assertResponseRedirects();
        $message = $this->sentMessages()[0] ?? null;
        self::assertInstanceOf(ImportArtistDiscography::class, $message);
        self::assertSame($artist->getId(), $message->artistId);
    }

    public function testShowingAHiddenAlbumQueuesItsTracks(): void
    {
        $this->client->loginUser(self::createUser('admin', admin: true));
        $album = self::createAlbum(self::createArtist(), name: 'Live à Paris', hidden: true);

        $crawler = $this->client->request('GET', sprintf('/admin/album/%d/edit', $album->getId()));
        $form = $crawler->filter('form[name="Album"]')->form();
        $this->client->submit($form, ['Album[hidden]' => false]);

        self::assertResponseRedirects();
        self::assertFalse(self::em()->find(Album::class, $album->getId())?->isHidden());
        $message = $this->sentMessages()[0] ?? null;
        self::assertInstanceOf(ImportAlbumTracks::class, $message);
        self::assertSame($album->getId(), $message->albumId);
    }

    /**
     * Le traitement de la file lui-même est couvert par CronControllerTest (même
     * QueueRunner). Ici : le bouton répond, rend compte, et l'admin reste connecté. Le
     * reset des services par le worker le déconnectait avant l'option --no-reset.
     */
    public function testProcessQueueButtonKeepsTheAdminLoggedIn(): void
    {
        $this->client->loginUser(self::createUser('admin', admin: true));

        $crawler = $this->client->request('GET', '/admin/artist');
        $this->client->submit($crawler->filter('form[action*="/process-queue"]')->form());

        self::assertResponseRedirects('/admin/artist');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'message(s) traité(s)');
    }

    public function testProcessQueueButtonDoesNotStartASecondWorker(): void
    {
        $this->client->loginUser(self::createUser('admin', admin: true));
        $lock = self::getContainer()->get(LockFactory::class)->createLock('messenger-queue-runner');
        self::assertTrue($lock->acquire(), 'Simule le planificateur en train de tourner');

        $crawler = $this->client->request('GET', '/admin/artist');
        $this->client->submit($crawler->filter('form[action*="/process-queue"]')->form());
        $this->client->followRedirect();

        self::assertSelectorTextContains('body', 'Un traitement est déjà en cours');
        $lock->release();
    }

    /**
     * @return list<object>
     */
    private function sentMessages(): array
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');

        return array_values(array_map(static fn ($envelope) => $envelope->getMessage(), $transport->getSent()));
    }
}
