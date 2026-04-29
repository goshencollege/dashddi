<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260429205517 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE dnssec_ksk_rollover (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(32) NOT NULL, algorithm VARCHAR(32) NOT NULL, key_directory VARCHAR(255) NOT NULL, old_key_file VARCHAR(128) DEFAULT NULL, old_key_tag INT DEFAULT NULL, new_key_file VARCHAR(128) DEFAULT NULL, new_key_tag INT DEFAULT NULL, ds_record LONGTEXT DEFAULT NULL, dnskey_ttl_seconds INT DEFAULT NULL, started_at DATETIME NOT NULL, completed_at DATETIME DEFAULT NULL, log JSON DEFAULT NULL, domain_id INT NOT NULL, dns_server_id INT DEFAULT NULL, INDEX IDX_575EABB7115F0EE5 (domain_id), INDEX IDX_575EABB7D1CF5272 (dns_server_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE dnssec_ksk_rollover ADD CONSTRAINT FK_575EABB7115F0EE5 FOREIGN KEY (domain_id) REFERENCES domain (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE dnssec_ksk_rollover ADD CONSTRAINT FK_575EABB7D1CF5272 FOREIGN KEY (dns_server_id) REFERENCES dns_server (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dnssec_ksk_rollover DROP FOREIGN KEY FK_575EABB7115F0EE5');
        $this->addSql('ALTER TABLE dnssec_ksk_rollover DROP FOREIGN KEY FK_575EABB7D1CF5272');
        $this->addSql('DROP TABLE dnssec_ksk_rollover');
    }
}
