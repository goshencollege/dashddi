<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260430100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move DNSSEC key directory from domain/subnet to dns_server; add per-zone subdirectory derivation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dns_server ADD key_directory VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE domain DROP COLUMN key_directory');
        $this->addSql('ALTER TABLE subnet DROP COLUMN key_directory');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dns_server DROP COLUMN key_directory');
        $this->addSql('ALTER TABLE domain ADD key_directory VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE subnet ADD key_directory VARCHAR(255) DEFAULT NULL');
    }
}
