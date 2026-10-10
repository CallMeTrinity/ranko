<?php

namespace App\Tests\Controller;

use App\Entity\Album;
use App\Entity\CatalogItem;
use App\Entity\Ranking;
use App\Enum\CatalogItemKind;
use App\Ranking\RankingManager;
use App\Tests\Support\CreatesEntities;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RankingControllerTest extends WebTestCase
{
    use CreatesEntities;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testCreateScopedRankingFromAlbumPage(): void
    {
        $this->client->loginUser(self::createUser());
        $album = $this->album();

        $this->client->request('GET', '/albums/'.$album->getId());
        $this->client->submitForm("Classer les titres de l'album");

        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Titres de OK Computer');
        self::assertCount(3, $crawler->filter('[data-item-id]'));
    }

    public function testPrivateRankingIsOnlyVisibleToItsOwner(): void
    {
        $ranking = $this->ranking();

        $this->client->request('GET', '/rankings/'.$ranking->getId());
        self::assertResponseRedirects('/login');

        $this->client->loginUser(self::createUser('bob'));
        $this->client->request('GET', '/rankings/'.$ranking->getId());
        self::assertResponseStatusCodeSame(403);
    }

    public function testOwnerMakesRankingPublicThenOthersCanReadButNotEdit(): void
    {
        $ranking = $this->ranking();
        $this->client->loginUser($ranking->getOwner());

        $this->client->request('GET', '/rankings/'.$ranking->getId());
        $this->client->submitForm('Rendre public');
        self::assertResponseRedirects();

        $this->client->loginUser(self::createUser('bob'));
        $crawler = $this->client->request('GET', '/rankings/'.$ranking->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-controller="sortable"]'), 'Pas de drag & drop pour un visiteur');

        $this->client->jsonRequest('POST', '/rankings/'.$ranking->getId().'/order', ['itemIds' => [1, 2, 3]]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testReorderWithDragAndDropEndpoint(): void
    {
        $ranking = $this->ranking();
        $this->client->loginUser($ranking->getOwner());
        $token = $this->client->request('GET', '/rankings/'.$ranking->getId())
            ->filter('[data-controller="sortable"]')->attr('data-sortable-token-value');
        $ids = array_map(static fn (CatalogItem $item): int => (int) $item->getId(), $ranking->getItems());

        $this->client->jsonRequest('POST', '/rankings/'.$ranking->getId().'/order', ['itemIds' => array_reverse($ids)], ['HTTP_X-CSRF-Token' => $token]);
        self::assertResponseStatusCodeSame(204);

        $this->client->jsonRequest('POST', '/rankings/'.$ranking->getId().'/order', ['itemIds' => [$ids[0]]], ['HTTP_X-CSRF-Token' => $token]);
        self::assertResponseStatusCodeSame(422);

        $reloaded = self::em()->find(Ranking::class, $ranking->getId());
        self::assertSame(array_reverse($ids), array_map(static fn (CatalogItem $item): int => (int) $item->getId(), $reloaded?->getItems() ?? []));
    }

    public function testReorderRequiresCsrfToken(): void
    {
        $ranking = $this->ranking();
        $this->client->loginUser($ranking->getOwner());

        $this->client->jsonRequest('POST', '/rankings/'.$ranking->getId().'/order', ['itemIds' => [1]]);

        self::assertResponseRedirects('/login');
    }

    public function testRemoveItem(): void
    {
        $ranking = $this->ranking();
        $this->client->loginUser($ranking->getOwner());

        $crawler = $this->client->request('GET', '/rankings/'.$ranking->getId());
        $this->client->submit($crawler->filter('form[action$="/remove"]')->first()->form());

        $crawler = $this->client->followRedirect();
        self::assertCount(2, $crawler->filter('[data-item-id]'));
    }

    public function testAddTrackToGlobalRanking(): void
    {
        $this->client->loginUser(self::createUser());
        $album = $this->album();

        $crawler = $this->client->request('GET', '/albums/'.$album->getId());
        $this->client->submit($crawler->selectButton('+ Top titres')->first()->form());
        $this->client->followRedirect();

        self::assertSelectorTextContains('[data-flash="success"]', 'Top titres');
    }

    public function testCannotCreateRankingWithHiddenScope(): void
    {
        $this->client->loginUser(self::createUser());
        $token = $this->createToken();
        $hiddenAlbum = self::createAlbum(self::createArtist('Autre'), hidden: true);

        $this->client->request('POST', '/rankings', ['kind' => 'track', 'scopeId' => $hiddenAlbum->getId(), '_token' => $token]);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Token "ranking-create" lu dans une page qui affiche le bouton de création.
     */
    private function createToken(): string
    {
        $album = $this->album();
        $crawler = $this->client->request('GET', '/albums/'.$album->getId());

        return (string) $crawler->filter('form[action$="/rankings"] input[name="_token"]')->attr('value');
    }

    private function album(): Album
    {
        return self::createAlbum(self::createArtist(), 'OK Computer', ['Airbag', 'Paranoid Android', 'Subterranean Homesick Alien']);
    }

    private function ranking(): Ranking
    {
        return self::getContainer()->get(RankingManager::class)->findOrCreate(self::createUser(), CatalogItemKind::Track, $this->album());
    }
}
