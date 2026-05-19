<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260518183510 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE snipe_it_asset_link (id INT AUTO_INCREMENT NOT NULL, snipe_asset_id INT NOT NULL, snipe_asset_tag VARCHAR(255) NOT NULL, snipe_asset_name VARCHAR(255) NOT NULL, synced_at DATETIME NOT NULL, server_id INT NOT NULL, host_id INT NOT NULL, INDEX IDX_464BA7701844E6B7 (server_id), UNIQUE INDEX UNIQ_464BA7701FB8D185 (host_id), UNIQUE INDEX uq_snipe_asset (server_id, snipe_asset_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE snipe_it_server (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, api_url VARCHAR(255) NOT NULL, api_key VARCHAR(255) DEFAULT NULL, verify_tls TINYINT NOT NULL, mac_custom_fields LONGTEXT NOT NULL, description LONGTEXT DEFAULT NULL, last_sync_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, created_by VARCHAR(255) DEFAULT NULL, updated_by VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE snipe_it_asset_link ADD CONSTRAINT FK_464BA7701844E6B7 FOREIGN KEY (server_id) REFERENCES snipe_it_server (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE snipe_it_asset_link ADD CONSTRAINT FK_464BA7701FB8D185 FOREIGN KEY (host_id) REFERENCES host (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE snipe_it_asset_link DROP FOREIGN KEY FK_464BA7701844E6B7');
        $this->addSql('ALTER TABLE snipe_it_asset_link DROP FOREIGN KEY FK_464BA7701FB8D185');
        $this->addSql('DROP TABLE snipe_it_asset_link');
        $this->addSql('DROP TABLE snipe_it_server');
    }
}
