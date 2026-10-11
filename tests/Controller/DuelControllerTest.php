<?php

namespace App\Tests\Controller;

use App\Entity\Album;
use App\Entity\CatalogItem;
use App\Entity\Duel;
use App\Entity\Ranking;
use App\Entity\Track;
use App\Enum\CatalogItemKind;
use App\Ranking\RankingManager;
use App\Tests\Support\CreatesEntities;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class DuelControllerTest extends WebTestCase
{
    use CreatesEntities;

    /** Préférences cachées de l'utilisateur, de la préférée à la moins aimée. */
    private const array TASTE = ['Paranoid Android', 'Let Down', 'Airbag', 'Lucky', 'Karma Police', 'No Surprises'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testSortDuelProducesTheUsersOrder(): void
    {
        $ranking = $this->ranking(['Airbag', 'Paranoid Android', 'Let Down', 'Karma Police', 'No Surprises', 'Lucky']);

        $this->client->request('GET', '/rankings/'.$ranking->getId());
        $this->client->submitForm('Classer par duel');
        $crawler = $this->client->followRedirect();

        $questions = 0;
        while (null !== $crawler && $crawler->filter('[data-duel-target]')->count() > 0) {
            $crawler = $this->answerLikeTheUser($crawler);
            ++$questions;
        }

        self::assertSelectorTextContains('[data-flash="success"]', 'Duel terminé');
        self::assertSame(self::TASTE, $this->names($ranking));
        self::assertLessThanOrEqual(11, $questions, 'Borne du tri fusion pour 6 éléments');
        self::assertSame(0, self::em()->getRepository(Duel::class)->count([]), 'Le duel terminé est supprimé');
    }

    public function testDuelResumesAfterLeavingAndIgnoresReplayedAnswers(): void
    {
        $ranking = $this->ranking(['Airbag', 'Paranoid Android', 'Let Down', 'Lucky']);
        $this->client->request('GET', '/rankings/'.$ranking->getId());
        $this->client->submitForm('Classer par duel');
        $crawler = $this->client->followRedirect();

        $firstForm = $crawler->filter('form[action$="/duel/answer"]')->first()->form();
        $this->client->submit($firstForm);
        $this->client->submit($firstForm);

        // Nouvelle session : l'état vient de la base, pas de la session.
        $this->client->restart();
        $this->client->loginUser($ranking->getOwner());
        $this->client->request('GET', '/rankings/'.$ranking->getId());
        $this->client->clickLink('Reprendre le duel');

        self::assertSelectorTextContains('main', 'Question 2', 'La réponse rejouée a été ignorée');
    }

    public function testUndoLastAnswer(): void
    {
        $ranking = $this->ranking(['Airbag', 'Paranoid Android', 'Let Down']);
        $this->client->request('GET', '/rankings/'.$ranking->getId());
        $this->client->submitForm('Classer par duel');
        $crawler = $this->client->followRedirect();

        $this->client->submit($crawler->filter('form[action$="/duel/answer"]')->first()->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Question 2');

        $this->client->submitForm('Annuler la dernière réponse');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Question 1');
    }

    public function testPlaceMissingTrackByBinaryInsertion(): void
    {
        $ranking = $this->ranking(['Paranoid Android', 'Airbag', 'No Surprises']);
        $album = $ranking->getScope();
        self::assertInstanceOf(Album::class, $album);
        $track = (new Track($album, 'Let Down'))->setPosition(10);
        $album->getTracks()->add($track);
        self::em()->persist($track);
        self::em()->flush();

        $this->client->request('GET', '/rankings/'.$ranking->getId());
        $this->client->submitForm('Placer par duel');
        $crawler = $this->client->followRedirect();

        while (null !== $crawler && $crawler->filter('[data-duel-target]')->count() > 0) {
            $crawler = $this->answerLikeTheUser($crawler);
        }

        self::assertSame(['Paranoid Android', 'Let Down', 'Airbag', 'No Surprises'], $this->names($ranking));
    }

    public function testOnlyTheOwnerCanDuel(): void
    {
        $ranking = $this->ranking(['Airbag', 'Lucky']);
        $this->client->loginUser(self::createUser('bob'));

        $this->client->request('GET', '/rankings/'.$ranking->getId().'/duel');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Répond comme l'utilisateur (selon TASTE) et renvoie la page suivante, ou null
     * quand le duel est fini et qu'on est revenu sur le classement.
     */
    private function answerLikeTheUser(Crawler $crawler): ?Crawler
    {
        $names = $crawler->filter('[data-duel-name]')->each(static fn (Crawler $node): string => trim($node->text()));
        $winner = array_search($names[0], self::TASTE, true) < array_search($names[1], self::TASTE, true) ? 0 : 1;

        $this->client->submit($crawler->filter('form[action$="/duel/answer"]')->eq($winner)->form());
        $crawler = $this->client->followRedirect();

        return $crawler->filter('[data-duel-target]')->count() > 0 ? $crawler : null;
    }

    /**
     * @param list<string> $trackNames
     */
    private function ranking(array $trackNames): Ranking
    {
        $owner = self::createUser();
        $album = self::createAlbum(self::createArtist(), 'OK Computer', $trackNames);
        $ranking = self::getContainer()->get(RankingManager::class)->findOrCreate($owner, CatalogItemKind::Track, $album);
        $this->client->loginUser($owner);

        return $ranking;
    }

    /**
     * @return list<string>
     */
    private function names(Ranking $ranking): array
    {
        $reloaded = self::em()->getRepository(Ranking::class)->findWithItems((int) $ranking->getId());

        return array_map(static fn (CatalogItem $item): string => $item->getName(), $reloaded?->getItems() ?? []);
    }
}
