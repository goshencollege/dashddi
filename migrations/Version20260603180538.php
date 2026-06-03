<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260603180538 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add version_scope to dhcp_server';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE dhcp_server ADD version_scope VARCHAR(4) NOT NULL DEFAULT 'both'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dhcp_server DROP version_scope');
    }
}
