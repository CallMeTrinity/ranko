<?php

namespace App\Tests\Controller\Admin;

use App\Entity\SpotifyAccount;
use App\Entity\User;
use App\Spotify\SpotifyAccess;
use App\Tests\Support\CreatesEntities;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;

/**
 * SPOTIFY_MAX_USERS vaut 2 en test (.env.test).
 */
final class UserAdminTest extends WebTestCase
{
    use CreatesEntities;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->loginUser(self::createUser('admin', admin: true));
    }

    public function testRegularUserIsForbidden(): void
    {
        $this->client->loginUser(self::createUser('bob'));
        $this->client->request('GET', '/admin/user');

        self::assertResponseStatusCodeSame(403);
    }

    public function testGrantsSeatsUpToTheLimit(): void
    {
        $bob = self::createUser('bob');
        $carol = self::createUser('carol');
        $dave = self::createUser('dave');

        $crawler = $this->client->request('GET', '/admin/user');
        self::assertSelectorTextContains('h1', 'Spotify 0 / 2 places');
        self::assertStringContainsString('User Management', (string) $crawler->filter('.content-header-help')->attr('data-bs-content'));

        $this->grant($bob);
        $this->grant($carol);
        $crawler = $this->grant($dave);

        self::assertSelectorTextContains('h1', 'Spotify 2 / 2 places');
        self::assertStringContainsString('Les 2 places Spotify sont prises', $crawler->text());
self::assertCount(2, $crawler->filter('td:contains("Activé")'));
        self::assertTrue($this->reload($bob)->hasRole(SpotifyAccess::ROLE));
        self::assertTrue($this->reload($carol)->hasRole(SpotifyAccess::ROLE));
        self::assertFalse($this->reload($dave)->hasRole(SpotifyAccess::ROLE));
    }

    public function testRevokingFreesTheSeatAndForgetsTheTokens(): void
    {
        $bob = self::createUser('bob')->addRole(SpotifyAccess::ROLE);
        self::em()->persist(new SpotifyAccount($bob, 'bob-spotify', null, 'token', 'refresh', new \DateTimeImmutable('+1 hour')));
        self::em()->flush();

        $crawler = $this->client->request('GET', '/admin/user');
        $this->client->submit($this->actionForm($crawler, 'revoke-spotify', $bob));
        $this->client->followRedirect();

        self::assertSelectorTextContains('h1', 'Spotify 0 / 2 places');
        self::assertFalse($this->reload($bob)->hasRole(SpotifyAccess::ROLE));
        self::assertNull(self::em()->getRepository(SpotifyAccount::class)->findOneBy(['user' => $bob->getId()]));
    }

    private function grant(User $user): Crawler
    {
        $crawler = $this->client->request('GET', '/admin/user');
        $this->client->submit($this->actionForm($crawler, 'grant-spotify', $user));
        self::assertResponseRedirects();

        return $this->client->followRedirect();
    }

    private function actionForm(Crawler $crawler, string $action, User $user): Form
    {
        return $crawler->filter(sprintf('form[action*="/admin/user/%d/%s"]', $user->getId(), $action))->form();
    }

    private function reload(User $user): User
    {
        self::em()->clear();

        return self::em()->getRepository(User::class)->find($user->getId()) ?? throw new \LogicException('Utilisateur disparu.');
    }
}
