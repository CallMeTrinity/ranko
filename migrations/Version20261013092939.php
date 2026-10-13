<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261013092939 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Synchro Spotify : comptes connectés, playlists, correspondance des titres, ISRC';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE spotify_account (id INT AUTO_INCREMENT NOT NULL, connected_at DATETIME NOT NULL, spotify_user_id VARCHAR(64) NOT NULL, display_name VARCHAR(255) DEFAULT NULL, access_token LONGTEXT NOT NULL, refresh_token LONGTEXT NOT NULL, expires_at DATETIME NOT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_E36E7423A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE spotify_playlist (id INT AUTO_INCREMENT NOT NULL, playlist_id VARCHAR(64) DEFAULT NULL, spotify_user_id VARCHAR(64) DEFAULT NULL, url VARCHAR(255) DEFAULT NULL, snapshot_id VARCHAR(255) DEFAULT NULL, requested_at DATETIME NOT NULL, synced_at DATETIME DEFAULT NULL, missing_count INT NOT NULL, error VARCHAR(255) DEFAULT NULL, ranking_id INT NOT NULL, UNIQUE INDEX UNIQ_A4C05F8C20F64684 (ranking_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE spotify_track_match (id INT AUTO_INCREMENT NOT NULL, uri VARCHAR(64) DEFAULT NULL, checked_at DATETIME NOT NULL, track_id INT NOT NULL, UNIQUE INDEX UNIQ_243E12B15ED23C43 (track_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE spotify_account ADD CONSTRAINT FK_E36E7423A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE spotify_playlist ADD CONSTRAINT FK_A4C05F8C20F64684 FOREIGN KEY (ranking_id) REFERENCES ranking (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE spotify_track_match ADD CONSTRAINT FK_243E12B15ED23C43 FOREIGN KEY (track_id) REFERENCES track (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE track ADD isrc VARCHAR(12) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE spotify_account DROP FOREIGN KEY FK_E36E7423A76ED395');
        $this->addSql('ALTER TABLE spotify_playlist DROP FOREIGN KEY FK_A4C05F8C20F64684');
        $this->addSql('ALTER TABLE spotify_track_match DROP FOREIGN KEY FK_243E12B15ED23C43');
        $this->addSql('DROP TABLE spotify_account');
        $this->addSql('DROP TABLE spotify_playlist');
        $this->addSql('DROP TABLE spotify_track_match');
        $this->addSql('ALTER TABLE track DROP isrc');
    }
}
