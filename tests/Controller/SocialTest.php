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

final class SocialTest extends WebTestCase
{
    use CreatesEntities;

    private KernelBrowser $client;
    private Album $album;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->album = self::createAlbum(self::createArtist(), 'OK Computer', ['Airbag', 'Paranoid Android', 'Let Down']);
    }

    public function testProfileListsOnlyPublicRankings(): void
    {
        $alice = self::createUser('alice');
        $this->ranking($alice, ['Airbag', 'Paranoid Android', 'Let Down'], Visibility::Public);
        $this->globalRanking($alice, Visibility::Private);

        $this->client->request('GET', '/u/alice');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Titres de OK Computer');
        self::assertSelectorTextNotContains('main', 'Top artistes');
        self::assertSelectorTextContains('main ol', 'Airbag', 'Aperçu du top 3');

        $this->client->request('GET', '/u/inconnu');
        self::assertResponseStatusCodeSame(404);
    }

    public function testExploreFiltersByKindAndHidesPrivateRankings(): void
    {
        $alice = self::createUser('alice');
        $this->ranking($alice, ['Airbag', 'Paranoid Android', 'Let Down'], Visibility::Public);
        $this->globalRanking(self::createUser('bob'), Visibility::Public);
        $this->globalRanking(self::createUser('carol'), Visibility::Private);

        $this->client->request('GET', '/explore');
        self::assertSelectorTextContains('main', 'Titres de OK Computer');
        self::assertSelectorTextContains('main', 'bob');
        self::assertSelectorTextNotContains('main', 'carol');

        $this->client->request('GET', '/explore?kind=artist');
        self::assertSelectorTextNotContains('main', 'Titres de OK Computer');

        $this->client->request('GET', '/');
        self::assertSelectorTextContains('main', 'Derniers classements publics');

        $this->client->request('GET', '/albums/'.$this->album->getId());
        self::assertSelectorTextContains('#community-title', 'communauté');
    }

    public function testCompareTwoRankingsWithKendallAgreement(): void
    {
        $alice = self::createUser('alice');
        $bob = self::createUser('bob');
        $aliceRanking = $this->ranking($alice, ['Airbag', 'Paranoid Android', 'Let Down'], Visibility::Public);
        $bobRanking = $this->ranking($bob, ['Airbag', 'Let Down', 'Paranoid Android'], Visibility::Private);

        // Bob consulte le classement public d'Alice et se compare.
        $this->client->loginUser($bob);
        $this->client->request('GET', '/rankings/'.$aliceRanking->getId());
        $this->client->clickLink('Titres de OK Computer');

        self::assertResponseIsSuccessful();
        // Une paire inversée sur trois : 2/3 d'accord.
        self::assertSelectorTextContains('h2', '67 % d\'accord');
        self::assertSelectorTextContains('main', 'τ de Kendall = 0,33');

        // Alice ne peut pas lire le classement privé de Bob, donc pas la comparaison.
        $this->client->loginUser($alice);
        $this->client->request('GET', sprintf('/rankings/%d/compare/%d', $aliceRanking->getId(), $bobRanking->getId()));
        self::assertResponseStatusCodeSame(403);
    }

    public function testCannotCompareRankingsOfDifferentKinds(): void
    {
        $alice = self::createUser('alice');
        $tracks = $this->ranking($alice, ['Airbag', 'Let Down', 'Paranoid Android'], Visibility::Public);
        $artists = $this->globalRanking($alice, Visibility::Public);
        $this->client->loginUser($alice);

        $this->client->request('GET', sprintf('/rankings/%d/compare/%d', $tracks->getId(), $artists->getId()));

        self::assertResponseStatusCodeSame(404);
    }

    public function testPublicRankingHasShareLinkAndPreviewMetadata(): void
    {
        $ranking = $this->ranking(self::createUser('alice'), ['Paranoid Android', 'Airbag', 'Let Down'], Visibility::Public);

        $crawler = $this->client->request('GET', '/rankings/'.$ranking->getId());

        self::assertSame('http://localhost/rankings/'.$ranking->getId(), $crawler->filter('input[aria-label="Lien de partage"]')->attr('value'));
        self::assertStringStartsWith('1. Paranoid Android', (string) $crawler->filter('meta[property="og:description"]')->attr('content'));
    }

    /**
     * @param list<string> $order
     */
    private function ranking(User $owner, array $order, Visibility $visibility): Ranking
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

    private function globalRanking(User $owner, Visibility $visibility): Ranking
    {
        $ranking = self::getContainer()->get(RankingManager::class)->addToGlobalRanking($owner, $this->album->getArtist());
        $ranking->setVisibility($visibility);
        self::em()->flush();

        return $ranking;
    }
}
