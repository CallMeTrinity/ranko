<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261013095354 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Synchro Spotify automatique : message en file par playlist';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE spotify_playlist ADD queued TINYINT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE spotify_playlist DROP queued');
    }
}
