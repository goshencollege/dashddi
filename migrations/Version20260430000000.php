<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260430000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'KSK rollover: make domain_id nullable, add subnet_id for reverse-zone rollovers';
    }

    public function up(Schema $schema): void
    {
        // Make domain_id nullable (rollover may target a subnet reverse zone instead)
        $this->addSql('ALTER TABLE dnssec_ksk_rollover DROP FOREIGN KEY FK_575EABB7115F0EE5');
        $this->addSql('ALTER TABLE dnssec_ksk_rollover MODIFY domain_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE dnssec_ksk_rollover ADD CONSTRAINT FK_575EABB7115F0EE5 FOREIGN KEY (domain_id) REFERENCES domain (id) ON DELETE CASCADE');

        // Add subnet_id for reverse-zone rollovers
        $this->addSql('ALTER TABLE dnssec_ksk_rollover ADD subnet_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE dnssec_ksk_rollover ADD CONSTRAINT FK_575EABB7_subnet FOREIGN KEY (subnet_id) REFERENCES subnet (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_575EABB7_subnet ON dnssec_ksk_rollover (subnet_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dnssec_ksk_rollover DROP FOREIGN KEY FK_575EABB7_subnet');
        $this->addSql('DROP INDEX IDX_575EABB7_subnet ON dnssec_ksk_rollover');
        $this->addSql('ALTER TABLE dnssec_ksk_rollover DROP COLUMN subnet_id');

        $this->addSql('ALTER TABLE dnssec_ksk_rollover DROP FOREIGN KEY FK_575EABB7115F0EE5');
        $this->addSql('ALTER TABLE dnssec_ksk_rollover MODIFY domain_id INT NOT NULL');
        $this->addSql('ALTER TABLE dnssec_ksk_rollover ADD CONSTRAINT FK_575EABB7115F0EE5 FOREIGN KEY (domain_id) REFERENCES domain (id) ON DELETE CASCADE');
    }
}
