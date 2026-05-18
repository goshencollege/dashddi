<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260518175205 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE lock_keys (key_id VARCHAR(64) NOT NULL, key_token VARCHAR(44) NOT NULL, key_expiration INT UNSIGNED NOT NULL, PRIMARY KEY (key_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE clearpass_auth_log CHANGE auth_timestamp auth_timestamp DATETIME NOT NULL, CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE clearpass_auth_log RENAME INDEX idx_clearpass_server TO IDX_99D106AEEC1450BD');
        $this->addSql('ALTER TABLE clearpass_auth_log RENAME INDEX idx_clearpass_iface TO IDX_99D106AECE793EEA');
        $this->addSql('ALTER TABLE clearpass_server CHANGE last_auth_log_pull last_auth_log_pull DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE lock_keys');
        $this->addSql('ALTER TABLE clearpass_auth_log CHANGE auth_timestamp auth_timestamp DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE clearpass_auth_log RENAME INDEX idx_99d106aece793eea TO IDX_clearpass_iface');
        $this->addSql('ALTER TABLE clearpass_auth_log RENAME INDEX idx_99d106aeec1450bd TO IDX_clearpass_server');
        $this->addSql('ALTER TABLE clearpass_server CHANGE last_auth_log_pull last_auth_log_pull DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }
}
