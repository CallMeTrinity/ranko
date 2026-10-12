<?php

namespace App\Tests\Ranking;

use App\Entity\CatalogItem;
use App\Entity\Ranking;
use App\Enum\CatalogItemKind;
use App\Ranking\RankingManager;
use App\Tests\Support\CreatesEntities;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RankingManagerTest extends KernelTestCase
{
    use CreatesEntities;

    private RankingManager $manager;

    protected function setUp(): void
    {
        $this->manager = self::getContainer()->get(RankingManager::class);
    }

    public function testScopedRankingIsPrefilledInCatalogOrderWithoutHiddenItems(): void
    {
        $artist = self::createArtist();
        self::createAlbum($artist, 'OK Computer', releaseDate: '1997-05-21');
        self::createAlbum($artist, 'Pablo Honey', releaseDate: '1993-02-22');
        self::createAlbum($artist, 'Live', hidden: true, releaseDate: '1994-01-01');

        $ranking = $this->manager->findOrCreate(self::createUser(), CatalogItemKind::Album, $artist);

        self::assertSame(['Pablo Honey', 'OK Computer'], $this->names($ranking));
    }

    public function testFindOrCreateReturnsTheExistingRanking(): void
    {
        $user = self::createUser();
        $album = self::createAlbum(self::createArtist(), trackNames: ['Airbag', 'Paranoid Android']);

        $first = $this->manager->findOrCreate($user, CatalogItemKind::Track, $album);
        $second = $this->manager->findOrCreate($user, CatalogItemKind::Track, $album);
        $global = $this->manager->findOrCreate($user, CatalogItemKind::Track);

        self::assertSame($first->getId(), $second->getId());
        self::assertNotSame($first->getId(), $global->getId());
        self::assertSame([], $global->getItems(), 'Un classement global naît vide');
        self::assertSame($global->getId(), $this->manager->findOrCreate($user, CatalogItemKind::Track)->getId());
    }

    public function testCustomListsLiveNextToTheAutomaticRankingAndStartEmpty(): void
    {
        $user = self::createUser();
        $album = self::createAlbum(self::createArtist(), trackNames: ['Airbag', 'Paranoid Android']);

        $custom = $this->manager->createCustom($user, '  Pour courir ', CatalogItemKind::Track, 5, $album);
        $automatic = $this->manager->findOrCreate($user, CatalogItemKind::Track, $album);

        self::assertNotSame($custom->getId(), $automatic->getId());
        self::assertSame('Pour courir', $custom->getTitle());
        self::assertSame([], $custom->getItems(), 'Une liste personnalisée naît vide, même avec un périmètre');
        self::assertSame([], $this->manager->findMissingItems($custom), 'Ne pas être dans la liste est un choix');
        self::assertCount(2, $automatic->getItems());
    }

    public function testMissingItemsAreDetectedAndAppended(): void
    {
        $album = self::createAlbum(self::createArtist(), trackNames: ['Airbag', 'Paranoid Android']);
        $ranking = $this->manager->findOrCreate(self::createUser(), CatalogItemKind::Track, $album);
        $ranking->reorder(array_reverse(array_map(static fn (CatalogItem $item): int => (int) $item->getId(), $ranking->getItems())));

        // Un titre apparaît dans le catalogue après la création du classement.
        $track = new \App\Entity\Track($album, 'Lucky');
        $track->setPosition(3);
        $album->getTracks()->add($track);
        self::em()->persist($track);
        self::em()->flush();

        self::assertSame(['Lucky'], array_map(static fn (CatalogItem $item) => $item->getName(), $this->manager->findMissingItems($ranking)));

        $this->manager->appendMissingItems($ranking);

        self::assertSame(['Paranoid Android', 'Airbag', 'Lucky'], $this->names($ranking), "L'ordre existant est conservé");
        self::assertSame([], $this->manager->findMissingItems($ranking));
    }

    public function testAddToGlobalRanking(): void
    {
        $user = self::createUser();
        $artist = self::createArtist();

        $ranking = $this->manager->addToGlobalRanking($user, $artist);
        $this->manager->addToGlobalRanking($user, $artist);

        self::assertSame('Top artistes', $ranking->getTitle());
        self::assertSame(['Radiohead'], $this->names($ranking));
    }

    /**
     * @return list<string>
     */
    private function names(Ranking $ranking): array
    {
        return array_map(static fn (CatalogItem $item): string => $item->getName(), $ranking->getItems());
    }
}
