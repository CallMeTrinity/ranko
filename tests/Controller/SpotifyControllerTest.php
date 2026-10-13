<?php

namespace App\Tests\Controller;

use App\Entity\Ranking;
use App\Entity\SpotifyAccount;
use App\Entity\User;
use App\Enum\CatalogItemKind;
use App\Message\SyncRankingToSpotify;
use App\Spotify\SpotifyAccess;
use App\Tests\Fixtures\CatalogHttpMock;
use App\Tests\Support\CreatesEntities;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class SpotifyControllerTest extends WebTestCase
{
    use CreatesEntities;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        // Comme en local : Spotify refuse "localhost" (voir testRefusesToStartFromLocalhost).
        $this->client = self::createClient([], ['HTTP_HOST' => '127.0.0.1']);
        // Même conteneur entre les requêtes : on lit ensuite le transport en mémoire.
        $this->client->disableReboot();
    }

    public function testReservedToUsersWithASeat(): void
    {
        $user = self::createUser();
        $ranking = $this->ranking($user);
        $this->client->loginUser($user);

        $this->client->request('GET', '/spotify/connect');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/rankings/'.$ranking->getId());
        self::assertSelectorNotExists('[data-spotify]');
    }

    public function testConnectsAnAccountAndComesBackToTheRanking(): void
    {
        $user = $this->spotifyUser();
        $ranking = $this->ranking($user);
        $this->client->loginUser($user);

        $this->client->request('GET', '/rankings/'.$ranking->getId());
        $this->client->clickLink('Connecter Spotify');
        $state = $this->stateFromRedirect();

        $this->client->request('GET', '/spotify/callback', ['code' => 'abc', 'state' => $state]);
        self::assertResponseRedirects('/rankings/'.$ranking->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-flash="success"]', 'Compte Spotify "Alice S." connecté.');
        self::assertSelectorExists('[data-spotify] button:contains("Créer la playlist")');

        $account = $this->account($user);
        self::assertNotNull($account);
        self::assertSame(CatalogHttpMock::SPOTIFY_USER, $account->getSpotifyUserId());
        self::assertSame('fresh-refresh-token', $account->getRefreshToken());
    }

    public function testRefusesToStartFromLocalhost(): void
    {
        $user = $this->spotifyUser();
        $ranking = $this->ranking($user);
        $this->client->loginUser($user);

        $this->client->request('GET', 'http://localhost:8000/spotify/connect?ranking='.$ranking->getId());

        self::assertResponseRedirects('/rankings/'.$ranking->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-flash="error"]', 'http://127.0.0.1:8000');
    }

    public function testRejectsACallbackWithAnotherState(): void
    {
        $user = $this->spotifyUser();
        $this->client->loginUser($user);

        $this->client->request('GET', '/spotify/connect');
        $this->stateFromRedirect();
        $this->client->request('GET', '/spotify/callback', ['code' => 'abc', 'state' => 'forged']);

        self::assertResponseRedirects('/profile/edit');
        self::assertNull($this->account($user));
    }

    public function testDoesNotStoreAnAccountOutsideTheAllowlist(): void
    {
        $user = $this->spotifyUser();
        $this->client->loginUser($user);

        $this->client->request('GET', '/spotify/connect');
        $state = $this->stateFromRedirect();
        $this->client->request('GET', '/spotify/callback', ['code' => CatalogHttpMock::SPOTIFY_CODE_NOT_ALLOWLISTED, 'state' => $state]);

        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-flash="error"]', 'pas autorisé');
        self::assertNull($this->account($user));
    }

    public function testRequestingASyncQueuesItAndShowsItsState(): void
    {
        $user = $this->spotifyUser();
        $this->connectedAccount($user);
        $ranking = $this->ranking($user);
        $this->client->loginUser($user);

        $this->client->request('GET', '/rankings/'.$ranking->getId());
        $this->client->submitForm('Créer la playlist');

        self::assertResponseRedirects('/rankings/'.$ranking->getId());
        $message = $this->sentMessages()[0] ?? null;
        self::assertInstanceOf(SyncRankingToSpotify::class, $message);
        self::assertSame($ranking->getId(), $message->rankingId);

        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-spotify] [role="status"]', 'Synchro en cours');
    }

    public function testOnlyTrackRankingsBecomePlaylists(): void
    {
        $user = $this->spotifyUser();
        $this->connectedAccount($user);
        $ranking = new Ranking($user, CatalogItemKind::Album);
        self::em()->persist($ranking);
        self::em()->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/rankings/'.$ranking->getId());
        self::assertSelectorNotExists('[data-spotify]');
    }

    public function testDisconnectsFromTheProfile(): void
    {
        $user = $this->spotifyUser();
        $this->connectedAccount($user);
        $this->client->loginUser($user);

        $this->client->request('GET', '/profile/edit');
        self::assertSelectorTextContains('[data-spotify]', 'Alice S.');
        $this->client->submitForm('Déconnecter Spotify');

        self::assertResponseRedirects('/profile/edit');
        self::assertNull($this->account($user));
    }

    private function spotifyUser(): User
    {
        $user = self::createUser()->addRole(SpotifyAccess::ROLE);
        self::em()->flush();

        return $user;
    }

    private function connectedAccount(User $user): void
    {
        self::em()->persist(new SpotifyAccount($user, CatalogHttpMock::SPOTIFY_USER, 'Alice S.', 'token', 'refresh', new \DateTimeImmutable('+1 hour')));
        self::em()->flush();
    }

    private function account(User $user): ?SpotifyAccount
    {
        self::em()->clear();

        return self::em()->getRepository(SpotifyAccount::class)->findOneBy(['user' => $user->getId()]);
    }

    private function ranking(User $owner): Ranking
    {
        $album = self::createAlbum(self::createArtist(), trackNames: ['Airbag', 'Paranoid Android']);
        $ranking = new Ranking($owner, CatalogItemKind::Track, $album);
        foreach ($album->getTracks() as $track) {
            $ranking->add($track);
        }
        self::em()->persist($ranking);
        self::em()->flush();

        return $ranking;
    }

    private function stateFromRedirect(): string
    {
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://accounts.spotify.com/authorize?', $location);
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        self::assertSame('http://127.0.0.1/spotify/callback', $query['redirect_uri'] ?? null);

        $state = $query['state'] ?? null;
        self::assertIsString($state);

        return $state;
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
