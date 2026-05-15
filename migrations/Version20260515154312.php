<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260515154312 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE api_token CHANGE allowed_cidrs allowed_cidrs JSON NOT NULL');
        $this->addSql('ALTER TABLE clearpass_server CHANGE client_secret client_secret VARCHAR(255) DEFAULT NULL, CHANGE verify_tls verify_tls TINYINT NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE api_token CHANGE allowed_cidrs allowed_cidrs JSON DEFAULT \'_utf8mb4\\\\\'\'[]\\\\\'\'\' NOT NULL');
        $this->addSql('ALTER TABLE clearpass_server CHANGE client_secret client_secret VARCHAR(255) NOT NULL, CHANGE verify_tls verify_tls TINYINT DEFAULT 1 NOT NULL, CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }
}
