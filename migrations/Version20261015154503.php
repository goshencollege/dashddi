<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261015154503 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add enabled flag to denied_mac_address';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE denied_mac_address ADD enabled TINYINT(1) DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE denied_mac_address DROP enabled');
    }
}
