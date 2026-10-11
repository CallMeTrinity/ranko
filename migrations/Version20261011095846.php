<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261011095846 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bio du profil';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD bio VARCHAR(280) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP bio');
    }
}
