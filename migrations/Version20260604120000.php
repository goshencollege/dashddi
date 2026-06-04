<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260604120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add DDNS support: algorithm/secret on DnsServer, ddnsEnabled+ddnsDnsServer on Domain/Subnet, ddnsEnabled on DhcpServer';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dns_server ADD ddns_algorithm VARCHAR(32) DEFAULT NULL, ADD ddns_secret LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE domain ADD ddns_enabled TINYINT DEFAULT 0 NOT NULL, ADD ddns_dns_server_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE domain ADD CONSTRAINT FK_A7A91E0B2A5300B8 FOREIGN KEY (ddns_dns_server_id) REFERENCES dns_server (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_A7A91E0B2A5300B8 ON domain (ddns_dns_server_id)');
        $this->addSql('ALTER TABLE subnet ADD ddns_enabled TINYINT DEFAULT 0 NOT NULL, ADD ddns_qualifying_suffix VARCHAR(255) DEFAULT NULL, ADD ddns_dns_server_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE subnet ADD CONSTRAINT FK_91C242162A5300B8 FOREIGN KEY (ddns_dns_server_id) REFERENCES dns_server (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_91C242162A5300B8 ON subnet (ddns_dns_server_id)');
        $this->addSql('ALTER TABLE dhcp_server ADD ddns_enabled TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dhcp_server DROP ddns_enabled');
        $this->addSql('ALTER TABLE subnet DROP FOREIGN KEY FK_91C242162A5300B8');
        $this->addSql('DROP INDEX IDX_91C242162A5300B8 ON subnet');
        $this->addSql('ALTER TABLE subnet DROP ddns_enabled, DROP ddns_dns_server_id, DROP ddns_qualifying_suffix');
        $this->addSql('ALTER TABLE domain DROP FOREIGN KEY FK_A7A91E0B2A5300B8');
        $this->addSql('DROP INDEX IDX_A7A91E0B2A5300B8 ON domain');
        $this->addSql('ALTER TABLE domain DROP ddns_enabled, DROP ddns_dns_server_id');
        $this->addSql('ALTER TABLE dns_server DROP ddns_algorithm, DROP ddns_secret');
    }
}
