<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261012115628 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Listes personnalisées : nom et nombre de places';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ranking ADD name VARCHAR(80) DEFAULT NULL, ADD max_size INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ranking DROP name, DROP max_size');
    }
}
