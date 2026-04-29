<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260429185053 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE dnssec_policy (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(64) NOT NULL, description LONGTEXT DEFAULT NULL, dnskey_ttl INT DEFAULT NULL, max_zone_ttl INT DEFAULT NULL, signatures_validity VARCHAR(32) DEFAULT NULL, signatures_refresh VARCHAR(32) DEFAULT NULL, `keys` JSON DEFAULT NULL, extra_options LONGTEXT DEFAULT NULL, UNIQUE INDEX UNIQ_A6511D6D5E237E06 (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE domain ADD dnssec_inline_signing TINYINT NOT NULL, ADD key_directory VARCHAR(255) DEFAULT NULL, ADD dnssec_policy_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE domain ADD CONSTRAINT FK_A7A91E0BC54E909B FOREIGN KEY (dnssec_policy_id) REFERENCES dnssec_policy (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_A7A91E0BC54E909B ON domain (dnssec_policy_id)');
        $this->addSql('ALTER TABLE subnet ADD dnssec_inline_signing TINYINT NOT NULL, ADD key_directory VARCHAR(255) DEFAULT NULL, ADD dnssec_policy_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE subnet ADD CONSTRAINT FK_91C24216C54E909B FOREIGN KEY (dnssec_policy_id) REFERENCES dnssec_policy (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_91C24216C54E909B ON subnet (dnssec_policy_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE dnssec_policy');
        $this->addSql('ALTER TABLE domain DROP FOREIGN KEY FK_A7A91E0BC54E909B');
        $this->addSql('DROP INDEX IDX_A7A91E0BC54E909B ON domain');
        $this->addSql('ALTER TABLE domain DROP dnssec_inline_signing, DROP key_directory, DROP dnssec_policy_id');
        $this->addSql('ALTER TABLE subnet DROP FOREIGN KEY FK_91C24216C54E909B');
        $this->addSql('DROP INDEX IDX_91C24216C54E909B ON subnet');
        $this->addSql('ALTER TABLE subnet DROP dnssec_inline_signing, DROP key_directory, DROP dnssec_policy_id');
    }
}
