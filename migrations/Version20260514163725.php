<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260514163725 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE push_log CHANGE type type VARCHAR(16) NOT NULL');
        $this->addSql('ALTER TABLE radius_client CHANGE secret secret LONGTEXT NOT NULL');
        $this->addSql('ALTER TABLE radius_server CHANGE ssh_user ssh_user VARCHAR(64) NOT NULL, CHANGE remote_path remote_path VARCHAR(255) NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE push_log CHANGE type type VARCHAR(4) NOT NULL');
        $this->addSql('ALTER TABLE radius_client CHANGE secret secret VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE radius_server CHANGE ssh_user ssh_user VARCHAR(64) DEFAULT \'root\' NOT NULL, CHANGE remote_path remote_path VARCHAR(255) DEFAULT \'/etc/freeradius\' NOT NULL, CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }
}
