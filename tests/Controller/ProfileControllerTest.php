<?php

namespace App\Tests\Controller;

use App\Entity\Album;
use App\Entity\Ranking;
use App\Entity\User;
use App\Enum\CatalogItemKind;
use App\Enum\Visibility;
use App\Ranking\RankingManager;
use App\Tests\Support\CreatesEntities;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProfileControllerTest extends WebTestCase
{
    use CreatesEntities;

    private KernelBrowser $client;
    private Album $album;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->album = self::createAlbum(self::createArtist(), 'OK Computer', ['Airbag', 'Paranoid Android', 'Let Down']);
    }

    public function testStatsCountPublicRankingsAndDistinctItems(): void
    {
        $alice = self::createUser('alice');
        $this->albumRanking($alice, ['Airbag', 'Paranoid Android', 'Let Down'], Visibility::Public);
        // Airbag est aussi dans le classement global : compté une seule fois.
        $this->globalTrackRanking($alice, 'Airbag', Visibility::Public);
        $this->globalTrackRanking(self::createUser('bob'), 'Let Down', Visibility::Public);

        $this->client->request('GET', '/u/alice');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#stats-title + dl div:nth-child(1) dd', '2');
        self::assertSelectorTextContains('#stats-title + dl div:nth-child(2) dd', '3');
    }

    public function testShowcaseShowsNumberOneOfPublicGlobalRankingsOnly(): void
    {
        $alice = self::createUser('alice');
        $this->globalTrackRanking($alice, 'Paranoid Android', Visibility::Public);
        $this->albumRanking($alice, ['Let Down', 'Airbag', 'Paranoid Android'], Visibility::Public);

        $this->client->request('GET', '/u/alice');
        self::assertSelectorTextContains('#showcase-title + ul', 'N°1 des titres');
        self::assertSelectorTextContains('#showcase-title + ul', 'Paranoid Android');
        // Le n°1 d'un classement à périmètre n'est pas un n°1 global.
        self::assertSelectorTextNotContains('#showcase-title + ul', 'Let Down');

        $carol = self::createUser('carol');
        $this->globalTrackRanking($carol, 'Airbag', Visibility::Private);
        $this->client->request('GET', '/u/carol');
        self::assertSelectorNotExists('#showcase-title');
        self::assertSelectorNotExists('#stats-title');
    }

    public function testCompatibilityIsShownToAnotherSignedInUser(): void
    {
        $alice = self::createUser('alice');
        $bob = self::createUser('bob');
        $theirs = $this->albumRanking($alice, ['Airbag', 'Paranoid Android', 'Let Down'], Visibility::Public);
        // Privé : Bob le voit, lui, et la compatibilité se calcule de son point de vue.
        $mine = $this->albumRanking($bob, ['Airbag', 'Let Down', 'Paranoid Android'], Visibility::Private);

        $this->client->request('GET', '/u/alice');
        self::assertSelectorNotExists('#compatibility-title');

        $this->client->loginUser($bob);
        $crawler = $this->client->request('GET', '/u/alice');
        // Une paire inversée sur trois.
        self::assertSelectorTextContains('#compatibility-title', '67 % d\'accord avec alice');
        self::assertSame(
            sprintf('/rankings/%d/compare/%d', $mine->getId(), $theirs->getId()),
            $crawler->filter('#compatibility-title ~ ul a')->attr('href'),
        );

        $this->client->loginUser($alice);
        $this->client->request('GET', '/u/alice');
        self::assertSelectorNotExists('#compatibility-title', 'Pas de compatibilité avec soi-même');
    }

    public function testRankingsCanBeFilteredByKind(): void
    {
        $alice = self::createUser('alice');
        $this->albumRanking($alice, ['Airbag', 'Paranoid Android', 'Let Down'], Visibility::Public);
        $artists = self::getContainer()->get(RankingManager::class)->addToGlobalRanking($alice, $this->album->getArtist());
        $artists->setVisibility(Visibility::Public);
        self::em()->flush();

        $this->client->request('GET', '/u/alice');
        self::assertSelectorTextContains('nav[aria-label="Filtrer par type"]', 'Artistes');
        self::assertSelectorTextNotContains('nav[aria-label="Filtrer par type"]', 'Albums', 'Seuls les types classés ont un onglet');

        $this->client->request('GET', '/u/alice?kind=artist');
        self::assertSelectorTextContains('#profile-rankings', 'Top artistes');
        self::assertSelectorTextNotContains('#profile-rankings', 'Titres de OK Computer');
    }

    public function testOwnerEditsTheirBio(): void
    {
        $this->client->request('GET', '/profile/edit');
        self::assertResponseRedirects('/login');

        $alice = self::createUser('alice');
        $this->client->loginUser($alice);

        $this->client->request('GET', '/u/alice');
        $this->client->clickLink('Modifier le profil');
        $this->client->submitForm('Enregistrer', ['profile_form[bio]' => str_repeat('a', 281)]);
        self::assertResponseStatusCodeSame(422);

        $this->client->submitForm('Enregistrer', ['profile_form[bio]' => 'Fan de Radiohead depuis 1997.']);
        self::assertResponseRedirects('/u/alice');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main header', 'Fan de Radiohead depuis 1997.');

        $this->client->loginUser(self::createUser('bob'));
        $this->client->request('GET', '/u/alice');
        self::assertSelectorTextContains('main header', 'Fan de Radiohead depuis 1997.');
        self::assertSelectorTextNotContains('main header', 'Modifier le profil');
    }

    /**
     * @param list<string> $order
     */
    private function albumRanking(User $owner, array $order, Visibility $visibility): Ranking
    {
        $ranking = self::getContainer()->get(RankingManager::class)->findOrCreate($owner, CatalogItemKind::Track, $this->album);
        $byName = [];
        foreach ($ranking->getItems() as $item) {
            $byName[$item->getName()] = $item;
        }
        $ranking->reorder(array_map(static fn (string $name): int => (int) $byName[$name]->getId(), $order));
        $ranking->setVisibility($visibility);
        self::em()->flush();

        return $ranking;
    }

    private function globalTrackRanking(User $owner, string $trackName, Visibility $visibility): Ranking
    {
        $track = $this->album->getTracks()->findFirst(static fn (int $key, $track): bool => $track->getName() === $trackName);
        self::assertNotNull($track);

        $ranking = self::getContainer()->get(RankingManager::class)->addToGlobalRanking($owner, $track);
        $ranking->setVisibility($visibility);
        self::em()->flush();

        return $ranking;
    }
}
