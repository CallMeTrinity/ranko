<?php

namespace App\Tests\Spotify;

use App\Entity\CatalogItem;
use App\Entity\Ranking;
use App\Entity\SpotifyAccount;
use App\Entity\SpotifyPlaylist;
use App\Entity\User;
use App\Enum\CatalogItemKind;
use App\Message\SyncRankingToSpotify;
use App\MessageHandler\SyncRankingToSpotifyHandler;
use App\Ranking\RankingManager;
use App\Spotify\SpotifyAccess;
use App\Tests\Fixtures\CatalogHttpMock;
use App\Tests\Support\CreatesEntities;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Les modifications passent par RankingManager, comme depuis les contrôleurs.
 */
final class SpotifyAutoSyncTest extends KernelTestCase
{
    use CreatesEntities;

    private User $owner;
    private RankingManager $manager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->manager = self::getContainer()->get(RankingManager::class);
        $this->owner = self::createUser()->addRole(SpotifyAccess::ROLE);
        self::em()->persist(new SpotifyAccount($this->owner, CatalogHttpMock::SPOTIFY_USER, null, 'token', 'refresh', new \DateTimeImmutable('+1 hour')));
        self::em()->flush();
    }

    public function testChangesWaitingInQueueShareOneSync(): void
    {
        $ranking = $this->ranking();
        $playlist = $this->linkPlaylist($ranking);

        $this->manager->reorder($ranking, array_reverse($this->ids($ranking)));
        $this->manager->remove($ranking, $ranking->getItems()[0]);

        self::assertSame([$ranking->getId()], $this->sentRankingIds());
        self::assertTrue($playlist->isQueued());
        self::assertTrue($playlist->isPending());
    }

    public function testAChangeDuringOrAfterASyncSendsANewOne(): void
    {
        $ranking = $this->ranking();
        $this->linkPlaylist($ranking);

        $this->manager->reorder($ranking, array_reverse($this->ids($ranking)));
        $handler = self::getContainer()->get(SyncRankingToSpotifyHandler::class);
        $handler(new SyncRankingToSpotify((int) $ranking->getId()));

        $playlist = self::em()->getRepository(SpotifyPlaylist::class)->findOneBy(['ranking' => $ranking]);
        self::assertNotNull($playlist);
        self::assertFalse($playlist->isQueued(), 'Le worker a pris la demande');
        self::assertNull($playlist->getError());

        $ranking = $this->reload($ranking);
        $this->manager->reorder($ranking, array_reverse($this->ids($ranking)));

        self::assertSame([$ranking->getId(), $ranking->getId()], $this->sentRankingIds());
    }

    public function testOnlyPlaylistsAlreadyCreated(): void
    {
        $withoutPlaylist = $this->ranking();
        $requestedOnly = $this->ranking('Kid A');
        self::em()->persist(new SpotifyPlaylist($requestedOnly));
        self::em()->flush();

        $this->manager->reorder($withoutPlaylist, array_reverse($this->ids($withoutPlaylist)));
        $this->manager->reorder($requestedOnly, array_reverse($this->ids($requestedOnly)));

        self::assertSame([], $this->sentRankingIds());
    }

    public function testNotWhenTheOwnerLostTheSeat(): void
    {
        $ranking = $this->ranking();
        $this->linkPlaylist($ranking);
        $this->owner->removeRole(SpotifyAccess::ROLE);
        self::em()->flush();

        $this->manager->reorder($ranking, array_reverse($this->ids($ranking)));

        self::assertSame([], $this->sentRankingIds());
    }

    public function testRenamingTheListSyncsThePlaylist(): void
    {
        $album = self::createAlbum(self::createArtist(), trackNames: ['Airbag']);
        $ranking = $this->manager->createCustom($this->owner, 'Pour courir', CatalogItemKind::Track, scope: $album);
        $this->linkPlaylist($ranking);

        $this->manager->rename($ranking, 'Pour le trail');

        self::assertSame([$ranking->getId()], $this->sentRankingIds());
    }

    public function testVisibilityIsNotAChangeOfTheList(): void
    {
        $ranking = $this->ranking();
        $this->linkPlaylist($ranking);

        $this->manager->toggleVisibility($ranking);

        self::assertSame([], $this->sentRankingIds());
    }

    private function ranking(string $album = 'OK Computer'): Ranking
    {
        $album = self::createAlbum(self::createArtist(), name: $album, trackNames: ['Airbag', 'Paranoid Android', 'Subterranean Homesick Alien']);
        $ranking = new Ranking($this->owner, CatalogItemKind::Track, $album);
        foreach ($album->getTracks() as $track) {
            $ranking->add($track);
        }
        self::em()->persist($ranking);
        self::em()->flush();

        return $ranking;
    }

    /**
     * Playlist créée et synchronisée une première fois.
     */
    private function linkPlaylist(Ranking $ranking): SpotifyPlaylist
    {
        $playlist = (new SpotifyPlaylist($ranking))->attach(CatalogHttpMock::SPOTIFY_PLAYLIST, CatalogHttpMock::SPOTIFY_USER, null);
        $playlist->markSynced('snapshot', 0, new \DateTimeImmutable());
        self::em()->persist($playlist);
        self::em()->flush();

        return $playlist;
    }

    /**
     * @return list<int>
     */
    private function ids(Ranking $ranking): array
    {
        return array_map(static fn (CatalogItem $item): int => (int) $item->getId(), $ranking->getItems());
    }

    private function reload(Ranking $ranking): Ranking
    {
        self::em()->clear();

        return self::em()->getRepository(Ranking::class)->find($ranking->getId()) ?? throw new \LogicException('Classement disparu.');
    }

    /**
     * @return list<int>
     */
    private function sentRankingIds(): array
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');

        return array_values(array_map(static function ($envelope): int {
            $message = $envelope->getMessage();
            self::assertInstanceOf(SyncRankingToSpotify::class, $message);

            return $message->rankingId;
        }, $transport->getSent()));
    }
}
