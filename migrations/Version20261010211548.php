<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261010211548 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE album (type VARCHAR(16) NOT NULL, secondary_types JSON NOT NULL, release_date VARCHAR(10) DEFAULT NULL, cover_url VARCHAR(255) DEFAULT NULL, tracks_imported_at DATETIME DEFAULT NULL, artist_id INT NOT NULL, id INT NOT NULL, INDEX IDX_39986E43B7970CF8 (artist_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE artist (sort_name VARCHAR(255) DEFAULT NULL, disambiguation VARCHAR(255) DEFAULT NULL, import_status VARCHAR(16) DEFAULT NULL, imported_at DATETIME DEFAULT NULL, id INT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE catalog_item (id INT AUTO_INCREMENT NOT NULL, hidden TINYINT NOT NULL, created_at DATETIME NOT NULL, name VARCHAR(255) NOT NULL, kind VARCHAR(16) NOT NULL, INDEX IDX_6AAE9568885DE9BD (hidden), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE external_reference (id INT AUTO_INCREMENT NOT NULL, provider VARCHAR(32) NOT NULL, external_id VARCHAR(64) NOT NULL, item_id INT NOT NULL, UNIQUE INDEX UNIQ_8AF8E60792C4739C9F75D7B0 (provider, external_id), UNIQUE INDEX UNIQ_8AF8E607126F525E92C4739C (item_id, provider), INDEX IDX_8AF8E607126F525E (item_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE track (disc_number INT NOT NULL, position INT NOT NULL, duration_ms INT DEFAULT NULL, album_id INT NOT NULL, id INT NOT NULL, INDEX IDX_D6E3F8A61137ABCF (album_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `user` (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, username VARCHAR(30) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_8D93D649E7927C74 (email), UNIQUE INDEX UNIQ_8D93D649F85E0677 (username), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE album ADD CONSTRAINT FK_39986E43BF396750 FOREIGN KEY (id) REFERENCES catalog_item (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE album ADD CONSTRAINT FK_39986E43B7970CF8 FOREIGN KEY (artist_id) REFERENCES artist (id)');
        $this->addSql('ALTER TABLE artist ADD CONSTRAINT FK_1599687BF396750 FOREIGN KEY (id) REFERENCES catalog_item (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE external_reference ADD CONSTRAINT FK_8AF8E607126F525E FOREIGN KEY (item_id) REFERENCES catalog_item (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE track ADD CONSTRAINT FK_D6E3F8A6BF396750 FOREIGN KEY (id) REFERENCES catalog_item (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE track ADD CONSTRAINT FK_D6E3F8A61137ABCF FOREIGN KEY (album_id) REFERENCES album (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE album DROP FOREIGN KEY FK_39986E43BF396750');
        $this->addSql('ALTER TABLE album DROP FOREIGN KEY FK_39986E43B7970CF8');
        $this->addSql('ALTER TABLE artist DROP FOREIGN KEY FK_1599687BF396750');
        $this->addSql('ALTER TABLE external_reference DROP FOREIGN KEY FK_8AF8E607126F525E');
        $this->addSql('ALTER TABLE track DROP FOREIGN KEY FK_D6E3F8A6BF396750');
        $this->addSql('ALTER TABLE track DROP FOREIGN KEY FK_D6E3F8A61137ABCF');
        $this->addSql('DROP TABLE album');
        $this->addSql('DROP TABLE artist');
        $this->addSql('DROP TABLE catalog_item');
        $this->addSql('DROP TABLE external_reference');
        $this->addSql('DROP TABLE track');
        $this->addSql('DROP TABLE `user`');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
