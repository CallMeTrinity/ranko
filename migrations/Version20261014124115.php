<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261014124115 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Playlist Spotify : nom donné dans Spotify, pour suivre le renommage d\'une liste';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE spotify_playlist ADD name VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE spotify_playlist DROP name');
    }
}
