<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260810161415 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add dnssec_disable_request table for the Disable DNSSEC workflow';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE dnssec_disable_request (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(32) NOT NULL, ds_records_at_start LONGTEXT DEFAULT NULL, retired_keys JSON DEFAULT NULL, started_at DATETIME NOT NULL, completed_at DATETIME DEFAULT NULL, log JSON DEFAULT NULL, domain_id INT DEFAULT NULL, subnet_id INT DEFAULT NULL, dns_server_id INT DEFAULT NULL, INDEX IDX_79DB12D0115F0EE5 (domain_id), INDEX IDX_79DB12D0C9CF9478 (subnet_id), INDEX IDX_79DB12D0D1CF5272 (dns_server_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE dnssec_disable_request ADD CONSTRAINT FK_79DB12D0115F0EE5 FOREIGN KEY (domain_id) REFERENCES domain (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE dnssec_disable_request ADD CONSTRAINT FK_79DB12D0C9CF9478 FOREIGN KEY (subnet_id) REFERENCES subnet (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE dnssec_disable_request ADD CONSTRAINT FK_79DB12D0D1CF5272 FOREIGN KEY (dns_server_id) REFERENCES dns_server (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dnssec_disable_request DROP FOREIGN KEY FK_79DB12D0115F0EE5');
        $this->addSql('ALTER TABLE dnssec_disable_request DROP FOREIGN KEY FK_79DB12D0C9CF9478');
        $this->addSql('ALTER TABLE dnssec_disable_request DROP FOREIGN KEY FK_79DB12D0D1CF5272');
        $this->addSql('DROP TABLE dnssec_disable_request');
    }
}
