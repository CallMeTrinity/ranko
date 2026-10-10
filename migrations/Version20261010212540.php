<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261010212540 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE ranking (id INT AUTO_INCREMENT NOT NULL, visibility VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, item_kind VARCHAR(16) NOT NULL, owner_id INT NOT NULL, scope_id INT DEFAULT NULL, INDEX IDX_80B839D0518E430043625D9F (visibility, updated_at), INDEX IDX_80B839D07E3C61F9 (owner_id), INDEX IDX_80B839D0682B5931 (scope_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ranking_entry (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, ranking_id INT NOT NULL, item_id INT NOT NULL, UNIQUE INDEX UNIQ_26E5CAC120F64684126F525E (ranking_id, item_id), INDEX IDX_26E5CAC120F64684 (ranking_id), INDEX IDX_26E5CAC1126F525E (item_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE ranking ADD CONSTRAINT FK_80B839D07E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ranking ADD CONSTRAINT FK_80B839D0682B5931 FOREIGN KEY (scope_id) REFERENCES catalog_item (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ranking_entry ADD CONSTRAINT FK_26E5CAC120F64684 FOREIGN KEY (ranking_id) REFERENCES ranking (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ranking_entry ADD CONSTRAINT FK_26E5CAC1126F525E FOREIGN KEY (item_id) REFERENCES catalog_item (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ranking DROP FOREIGN KEY FK_80B839D07E3C61F9');
        $this->addSql('ALTER TABLE ranking DROP FOREIGN KEY FK_80B839D0682B5931');
        $this->addSql('ALTER TABLE ranking_entry DROP FOREIGN KEY FK_26E5CAC120F64684');
        $this->addSql('ALTER TABLE ranking_entry DROP FOREIGN KEY FK_26E5CAC1126F525E');
        $this->addSql('DROP TABLE ranking');
        $this->addSql('DROP TABLE ranking_entry');
    }
}
