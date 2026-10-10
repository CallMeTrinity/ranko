<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261010213209 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE duel (id INT AUTO_INCREMENT NOT NULL, version INT DEFAULT 1 NOT NULL, state JSON NOT NULL, previous_state JSON DEFAULT NULL, updated_at DATETIME NOT NULL, type VARCHAR(16) NOT NULL, ranking_id INT NOT NULL, UNIQUE INDEX UNIQ_9BB4A76220F64684 (ranking_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE duel ADD CONSTRAINT FK_9BB4A76220F64684 FOREIGN KEY (ranking_id) REFERENCES ranking (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE duel DROP FOREIGN KEY FK_9BB4A76220F64684');
        $this->addSql('DROP TABLE duel');
    }
}
