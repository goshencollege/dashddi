<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260429233155 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dnssec_ksk_rollover CHANGE old_key_file old_key_file VARCHAR(255) DEFAULT NULL, CHANGE new_key_file new_key_file VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dnssec_ksk_rollover CHANGE old_key_file old_key_file VARCHAR(128) DEFAULT NULL, CHANGE new_key_file new_key_file VARCHAR(128) DEFAULT NULL');
    }
}
