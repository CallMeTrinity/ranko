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

    public function testCreateCustomListFromArtistPage(): void
    {
        $this->client->loginUser(self::createUser());
        $artist = $this->album()->getArtist();

        $crawler = $this->client->request('GET', '/artists/'.$artist->getId());
        $crawler = $this->client->click($crawler->selectLink('Créer une liste')->link());
        self::assertSame(['album', 'track'], $crawler->filter('input[name="custom_ranking_form[kind]"]')->each(static fn ($input): string => (string) $input->attr('value')), 'Dans un artiste : ses albums ou ses titres');

        $this->client->submitForm('Créer la liste', [
            'custom_ranking_form[name]' => 'Pour courir',
            'custom_ranking_form[kind]' => 'track',
            'custom_ranking_form[maxSize]' => '2',
        ]);

        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Pour courir');
        self::assertCount(0, $crawler->filter('ol [data-item-id]'), 'La liste naît vide');
        self::assertCount(3, $crawler->filter('[data-controller="pool"] [data-item-id]'), 'Le réservoir propose les titres de l\'artiste');
        self::assertSelectorTextContains('main', '0 / 2');
    }

    public function testCreateCustomListRequiresAName(): void
    {
        $this->client->loginUser(self::createUser());

        $this->client->request('GET', '/rankings/new');
        $this->client->submitForm('Créer la liste', ['custom_ranking_form[name]' => ' ']);

        self::assertResponseStatusCodeSame(422);
    }

    public function testAddFromPoolAtDropPositionUntilFull(): void
    {
        [$airbag, $paranoid, $subterranean] = $this->album()->getTracks()->toArray();
        $ranking = self::getContainer()->get(RankingManager::class)->createCustom(self::createUser(), 'Préférés', CatalogItemKind::Track, 2);
        $this->client->loginUser($ranking->getOwner());

        // + du réservoir : ajout à la fin.
        $crawler = $this->client->request('GET', '/rankings/'.$ranking->getId());
        $token = (string) $crawler->filter('[data-controller="pool"] input[name="_token"]')->attr('value');
        $this->client->submit($crawler->filter('[data-controller="pool"] form')->first()->form());
        self::assertResponseRedirects('/rankings/'.$ranking->getId());

        // Glisser-déposer : le formulaire part avec la position de dépôt.
        $this->client->request('POST', '/rankings/'.$ranking->getId().'/add', ['itemId' => $subterranean->getId(), 'position' => 1, '_token' => $token]);
        $crawler = $this->client->followRedirect();
        self::assertSame(['Subterranean Homesick Alien', 'Airbag'], $crawler->filter('ol [data-item-id] .truncate.font-semibold')->each(static fn ($node): string => $node->text()));
        self::assertCount(1, $crawler->filter('[data-controller="pool"] [data-item-id]'), 'Ce qui est classé quitte le réservoir');
        self::assertSame('true', $crawler->filter('ol[data-controller="sortable"]')->attr('data-sortable-full-value'));

        $this->client->request('POST', '/rankings/'.$ranking->getId().'/add', ['itemId' => $paranoid->getId(), '_token' => $token]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-flash="error"]', 'complet');
        self::assertCount(2, self::em()->find(Ranking::class, $ranking->getId())?->getItems() ?? []);
    }

    public function testPoolSearchesWithinScopeAndKind(): void
    {
        $album = $this->album();
        $other = self::createAlbum(self::createArtist('Portishead'), 'Dummy', ['Mysterons', 'Sour Times']);
        $ranking = self::getContainer()->get(RankingManager::class)->createCustom(self::createUser(), 'OK', CatalogItemKind::Track, scope: $album);
        $this->client->loginUser($ranking->getOwner());

        $crawler = $this->client->request('GET', '/rankings/'.$ranking->getId().'/pool?q=an');
        self::assertSame(['Paranoid Android', 'Subterranean Homesick Alien'], $crawler->filter('[data-item-id] .truncate.font-semibold')->each(static fn ($node): string => $node->text()));

        // Un titre hors périmètre est refusé même si on forge la requête.
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
        $mysterons = $other->getTracks()->first() ?: throw new \LogicException('Album sans titre.');
        $this->client->request('POST', '/rankings/'.$ranking->getId().'/add', ['itemId' => $mysterons->getId(), '_token' => $token]);
        $this->client->followRedirect();
        self::assertSelectorExists('[data-flash="error"]');
        self::assertSame([], self::em()->find(Ranking::class, $ranking->getId())?->getItems());
    }

    public function testPoolIsForTheOwnerOfACustomListOnly(): void
    {
        $custom = self::getContainer()->get(RankingManager::class)->createCustom(self::createUser(), 'OK', CatalogItemKind::Artist);
        $automatic = self::getContainer()->get(RankingManager::class)->findOrCreate($custom->getOwner(), CatalogItemKind::Artist);

        $this->client->loginUser($custom->getOwner());
        $this->client->request('GET', '/rankings/'.$automatic->getId().'/pool');
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser(self::createUser('bob'));
        $this->client->request('GET', '/rankings/'.$custom->getId().'/pool');
        self::assertResponseStatusCodeSame(403);
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
