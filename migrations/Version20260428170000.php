<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260428170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Kea control channel fields to dhcp_server';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dhcp_server
            ADD control_url      VARCHAR(255) NULL AFTER ssh_key_path,
            ADD control_user     VARCHAR(128) NULL AFTER control_url,
            ADD control_password VARCHAR(255) NULL AFTER control_user');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dhcp_server
            DROP COLUMN control_url,
            DROP COLUMN control_user,
            DROP COLUMN control_password');
    }
}
