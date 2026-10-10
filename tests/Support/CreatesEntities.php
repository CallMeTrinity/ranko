<?php

namespace App\Tests\Support;

use App\Entity\Album;
use App\Entity\Artist;
use App\Entity\Track;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Raccourcis pour créer des données de test. Chaque test tourne dans une transaction
 * annulée à la fin (dama/doctrine-test-bundle) : rien à nettoyer.
 */
trait CreatesEntities
{
    protected static function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    protected static function createUser(string $username = 'alice', bool $admin = false, string $password = 'secret123'): User
    {
        $user = new User($username.'@example.com', $username);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, $password));
        if ($admin) {
            $user->setRoles(['ROLE_ADMIN']);
        }

        static::em()->persist($user);
        static::em()->flush();

        return $user;
    }

    protected static function createArtist(string $name = 'Radiohead', bool $hidden = false): Artist
    {
        $artist = (new Artist($name))->setHidden($hidden);
        static::em()->persist($artist);
        static::em()->flush();

        return $artist;
    }

    /**
     * @param list<string> $trackNames
     */
    protected static function createAlbum(Artist $artist, string $name = 'OK Computer', array $trackNames = [], bool $hidden = false, ?string $releaseDate = null): Album
    {
        $album = (new Album($artist, $name))->setHidden($hidden)->setReleaseDate($releaseDate);
        $artist->getAlbums()->add($album);
        static::em()->persist($album);

        foreach ($trackNames as $index => $trackName) {
            $track = (new Track($album, $trackName))->setPosition($index + 1);
            $album->getTracks()->add($track);
            static::em()->persist($track);
        }

        static::em()->flush();

        return $album;
    }
}
