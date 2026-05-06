<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260506100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add server_type and primary_hostname to dns_server for secondary DNS support';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE dns_server ADD server_type VARCHAR(16) NOT NULL DEFAULT 'primary'");
        $this->addSql("ALTER TABLE dns_server ADD primary_hostname VARCHAR(255) DEFAULT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE dns_server DROP COLUMN primary_hostname");
        $this->addSql("ALTER TABLE dns_server DROP COLUMN server_type");
    }
}
