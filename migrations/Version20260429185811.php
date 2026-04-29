<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260429185811 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dnssec_policy ADD purge_keys VARCHAR(32) DEFAULT NULL, ADD publish_safety VARCHAR(32) DEFAULT NULL, ADD retire_safety VARCHAR(32) DEFAULT NULL, ADD nsec3param VARCHAR(64) DEFAULT NULL, CHANGE dnskey_ttl dnskey_ttl VARCHAR(32) DEFAULT NULL, CHANGE max_zone_ttl max_zone_ttl VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dnssec_policy DROP purge_keys, DROP publish_safety, DROP retire_safety, DROP nsec3param, CHANGE dnskey_ttl dnskey_ttl INT DEFAULT NULL, CHANGE max_zone_ttl max_zone_ttl INT DEFAULT NULL');
    }
}
