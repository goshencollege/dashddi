<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260713182925 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop control_user and control_password — reload is tunnelled over SSH so control agent auth is unnecessary';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dhcp_server DROP control_user, DROP control_password');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dhcp_server ADD control_user VARCHAR(128) DEFAULT NULL, ADD control_password LONGTEXT DEFAULT NULL');
    }
}
