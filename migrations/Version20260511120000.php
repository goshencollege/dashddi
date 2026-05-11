<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260511120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add exclude_dhcp_leases column to backup_setting';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE backup_setting ADD exclude_dhcp_leases TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE backup_setting DROP COLUMN exclude_dhcp_leases');
    }
}
