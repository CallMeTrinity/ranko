<?php

namespace App\Tests\Spotify;

use App\Entity\Ranking;
use App\Entity\SpotifyAccount;
use App\Entity\SpotifyPlaylist;
use App\Entity\SpotifyTrackMatch;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\CatalogItemKind;
use App\Message\SyncRankingToSpotify;
use App\MessageHandler\SyncRankingToSpotifyHandler;
use App\Spotify\SpotifyAccess;
use App\Tests\Fixtures\CatalogHttpMock;
use App\Tests\Support\CreatesEntities;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

use function Symfony\Component\Clock\now;

/**
 * La synchro telle que la lance le worker Messenger, contre le faux Spotify de CatalogHttpMock.
 */
final class SpotifyPlaylistSyncTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use CreatesEntities;

    private User $owner;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-10-13 12:00:00');

        $this->owner = self::createUser()->addRole(SpotifyAccess::ROLE);
        self::em()->flush();
    }

    public function testCreatesThePlaylistInRankingOrder(): void
    {
        $this->connect();
        $ranking = $this->ranking(['Paranoid Android', 'Airbag']);

        $this->sync($ranking);

        $playlist = $this->playlist($ranking);
        self::assertSame(CatalogHttpMock::SPOTIFY_PLAYLIST, $playlist->getPlaylistId());
        self::assertSame(CatalogHttpMock::SPOTIFY_USER, $playlist->getSpotifyUserId());
        self::assertSame('https://open.spotify.com/playlist/'.CatalogHttpMock::SPOTIFY_PLAYLIST, $playlist->getUrl());
        self::assertSame('snapshot-items', $playlist->getSnapshotId());
        self::assertSame(1, $playlist->getMissingCount(), 'Spotify n\'a pas "Paranoid Android" dans la fixture');
        self::assertFalse($playlist->isPending());
        self::assertNull($playlist->getError());

        self::assertSame(CatalogHttpMock::SPOTIFY_AIRBAG_BY_TEXT, $this->match('Airbag')?->getUri(), 'Le titre exact du même album, pas la reprise ni le remaster');
        self::assertNull($this->match('Paranoid Android')?->getUri());
    }

    public function testSearchesByIsrcFirst(): void
    {
        $this->connect();
        $ranking = $this->ranking(['Airbag']);
        $this->track('Airbag')->setIsrc(CatalogHttpMock::AIRBAG_ISRC);

        $this->sync($ranking);

        self::assertSame(CatalogHttpMock::SPOTIFY_AIRBAG_BY_ISRC, $this->match('Airbag')?->getUri());
    }

    public function testRetriesAMissingTrackOnlyAfterAWeek(): void
    {
        $this->connect();
        $ranking = $this->ranking(['Paranoid Android']);
        $this->sync($ranking);
        $checkedAt = $this->match('Paranoid Android')?->getCheckedAt();

        self::mockTime('+1 day');
        $this->sync($ranking);
        self::assertEquals($checkedAt, $this->match('Paranoid Android')?->getCheckedAt(), 'Pas de nouvelle recherche');

        self::mockTime('+7 days');
        $this->sync($ranking);
        self::assertGreaterThan($checkedAt, $this->match('Paranoid Android')?->getCheckedAt());
    }

    public function testRefreshesAnExpiredToken(): void
    {
        $account = $this->connect(expiresAt: '-1 hour');

        $this->sync($this->ranking(['Airbag']));

        self::assertSame('fresh-access-token', $account->getAccessToken());
        self::assertSame('fresh-refresh-token', $account->getRefreshToken());
        self::assertGreaterThan(new \DateTimeImmutable('2026-10-13 12:00:00'), $account->getExpiresAt());
    }

    public function testRecreatesAPlaylistDeletedInSpotify(): void
    {
        $this->connect();
        $ranking = $this->ranking(['Airbag']);
        $this->playlistFor($ranking)->attach(CatalogHttpMock::SPOTIFY_DELETED_PLAYLIST, CatalogHttpMock::SPOTIFY_USER, null);

        $this->sync($ranking);

        self::assertSame(CatalogHttpMock::SPOTIFY_PLAYLIST, $this->playlist($ranking)->getPlaylistId());
    }

    public function testRecreatesThePlaylistForAnotherSpotifyAccount(): void
    {
        $this->connect();
        $ranking = $this->ranking(['Airbag']);
        $this->playlistFor($ranking)->attach('playlistOfAnotherAccount', 'bob-spotify', null);

        $this->sync($ranking);

        self::assertSame(CatalogHttpMock::SPOTIFY_PLAYLIST, $this->playlist($ranking)->getPlaylistId());
    }

    public function testAccountOutsideTheAllowlistFailsWithoutRetry(): void
    {
        $this->connect(token: CatalogHttpMock::SPOTIFY_TOKEN_FORBIDDEN);
        $ranking = $this->ranking(['Airbag']);

        $this->sync($ranking);

        self::assertStringContainsString('pas autorisé', (string) $this->playlist($ranking)->getError());
    }

    public function testMissingScopeAsksToReconnectRatherThanBlamingTheAllowlist(): void
    {
        $this->connect(token: CatalogHttpMock::SPOTIFY_TOKEN_MISSING_SCOPE);
        $ranking = $this->ranking(['Airbag']);

        $this->sync($ranking);

        self::assertStringContainsString('reconnecte ton compte', (string) $this->playlist($ranking)->getError());
    }

    public function testRateLimitIsRetriedAfterTheDelayGivenBySpotify(): void
    {
        $this->connect(token: CatalogHttpMock::SPOTIFY_TOKEN_RATE_LIMITED);
        $ranking = $this->ranking(['Airbag']);

        try {
            $this->sync($ranking);
            self::fail('Le message doit être rejoué');
        } catch (RecoverableMessageHandlingException $exception) {
            self::assertSame(30_000, $exception->getRetryDelay());
            self::assertFalse($exception->forceRetry(), 'Le nombre d\'essais reste borné');
        }

        self::assertStringContainsString('nouvel essai', (string) $this->playlist($ranking)->getError());
    }

    public function testFailsWithoutConnectedAccount(): void
    {
        $ranking = $this->ranking(['Airbag']);

        $this->sync($ranking);

        self::assertSame('Aucun compte Spotify connecté.', $this->playlist($ranking)->getError());
    }

    private function connect(string $token = 'valid-token', string $expiresAt = '+1 hour'): SpotifyAccount
    {
        $account = new SpotifyAccount($this->owner, CatalogHttpMock::SPOTIFY_USER, 'Alice S.', $token, 'refresh-token', new \DateTimeImmutable('2026-10-13 12:00:00 '.$expiresAt));
        self::em()->persist($account);
        self::em()->flush();

        return $account;
    }

    /**
     * Titres de OK Computer, dans l'ordre donné. La synchro est déjà demandée.
     *
     * @param list<string> $trackNames
     */
    private function ranking(array $trackNames): Ranking
    {
        $album = self::createAlbum(self::createArtist(), trackNames: $trackNames);
        $ranking = new Ranking($this->owner, CatalogItemKind::Track, $album);
        foreach ($album->getTracks() as $track) {
            $ranking->add($track);
        }
        self::em()->persist($ranking);
        self::em()->flush();

        $this->playlistFor($ranking);

        return $ranking;
    }

    private function playlistFor(Ranking $ranking): SpotifyPlaylist
    {
        $playlist = self::em()->getRepository(SpotifyPlaylist::class)->findOneBy(['ranking' => $ranking]);
        if (null === $playlist) {
            $playlist = new SpotifyPlaylist($ranking);
            self::em()->persist($playlist);
        }
        self::em()->flush();

        return $playlist;
    }

    private function sync(Ranking $ranking): void
    {
        self::em()->flush();
        $this->playlistFor($ranking)->request(now());
        self::em()->flush();

        $handler = self::getContainer()->get(SyncRankingToSpotifyHandler::class);
        $handler(new SyncRankingToSpotify((int) $ranking->getId()));
    }

    private function playlist(Ranking $ranking): SpotifyPlaylist
    {
        $playlist = self::em()->getRepository(SpotifyPlaylist::class)->findOneBy(['ranking' => $ranking]);
        self::assertNotNull($playlist);

        return $playlist;
    }

    private function track(string $name): Track
    {
        $track = self::em()->getRepository(Track::class)->findOneBy(['name' => $name]);
        self::assertNotNull($track);

        return $track;
    }

    private function match(string $trackName): ?SpotifyTrackMatch
    {
        return self::em()->getRepository(SpotifyTrackMatch::class)->findOneBy(['track' => $this->track($trackName)]);
    }
}
