<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260428192249 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE building RENAME INDEX uniq_building_name TO UNIQ_E16F61D45E237E06');
        $this->addSql('ALTER TABLE dhcp_server CHANGE ssh_user ssh_user VARCHAR(64) NOT NULL, CHANGE remote_path remote_path VARCHAR(255) NOT NULL, CHANGE ssh_key_path ssh_key_path VARCHAR(255) NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE domain ADD soa_nameserver VARCHAR(255) DEFAULT NULL, ADD soa_email VARCHAR(255) DEFAULT NULL, ADD soa_refresh INT DEFAULT NULL, ADD soa_retry INT DEFAULT NULL, ADD soa_expire INT DEFAULT NULL, ADD soa_ttl INT DEFAULT NULL');
        $this->addSql('ALTER TABLE host RENAME INDEX idx_host_building TO IDX_CF2713FD4D2A7E12');
        $this->addSql('ALTER TABLE host_tag RENAME INDEX idx_b160d9071a0e9b39 TO IDX_F590106E1FB8D185');
        $this->addSql('ALTER TABLE host_tag RENAME INDEX idx_b160d907bad26311 TO IDX_F590106EBAD26311');
        $this->addSql('ALTER TABLE subnet RENAME INDEX idx_b54a7c1ae2d68cf TO IDX_91C2421668E6482D');
        $this->addSql('ALTER TABLE vrf RENAME INDEX uniq_657d5f265e237e06 TO UNIQ_16E512E95E237E06');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE building RENAME INDEX uniq_e16f61d45e237e06 TO UNIQ_building_name');
        $this->addSql('ALTER TABLE dhcp_server CHANGE ssh_user ssh_user VARCHAR(64) DEFAULT \'root\' NOT NULL, CHANGE remote_path remote_path VARCHAR(255) DEFAULT \'/etc/kea\' NOT NULL, CHANGE ssh_key_path ssh_key_path VARCHAR(255) DEFAULT \'/root/.ssh/id_ed25519\' NOT NULL, CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE domain DROP soa_nameserver, DROP soa_email, DROP soa_refresh, DROP soa_retry, DROP soa_expire, DROP soa_ttl');
        $this->addSql('ALTER TABLE host RENAME INDEX idx_cf2713fd4d2a7e12 TO IDX_host_building');
        $this->addSql('ALTER TABLE host_tag RENAME INDEX idx_f590106e1fb8d185 TO IDX_B160D9071A0E9B39');
        $this->addSql('ALTER TABLE host_tag RENAME INDEX idx_f590106ebad26311 TO IDX_B160D907BAD26311');
        $this->addSql('ALTER TABLE subnet RENAME INDEX idx_91c2421668e6482d TO IDX_B54A7C1AE2D68CF');
        $this->addSql('ALTER TABLE vrf RENAME INDEX uniq_16e512e95e237e06 TO UNIQ_657D5F265E237E06');
    }
}
