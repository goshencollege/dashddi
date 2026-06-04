<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260604130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace subnet DDNS fields (ddnsEnabled, ddnsDnsServer, ddnsQualifyingSuffix) with a single ddnsDomain FK';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subnet DROP FOREIGN KEY `FK_91C242162A5300B8`');
        $this->addSql('DROP INDEX IDX_91C242162A5300B8 ON subnet');
        $this->addSql('ALTER TABLE subnet DROP ddns_enabled, DROP ddns_qualifying_suffix, CHANGE ddns_dns_server_id ddns_domain_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE subnet ADD CONSTRAINT FK_91C24216936A8557 FOREIGN KEY (ddns_domain_id) REFERENCES domain (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_91C24216936A8557 ON subnet (ddns_domain_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subnet DROP FOREIGN KEY FK_91C24216936A8557');
        $this->addSql('DROP INDEX IDX_91C24216936A8557 ON subnet');
        $this->addSql('ALTER TABLE subnet ADD ddns_enabled TINYINT DEFAULT 0 NOT NULL, ADD ddns_qualifying_suffix VARCHAR(255) DEFAULT NULL, CHANGE ddns_domain_id ddns_dns_server_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE subnet ADD CONSTRAINT FK_91C242162A5300B8 FOREIGN KEY (ddns_dns_server_id) REFERENCES dns_server (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_91C242162A5300B8 ON subnet (ddns_dns_server_id)');
    }
}
