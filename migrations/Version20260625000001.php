<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260625000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add last_auth_at and last_dhcp_at to network_interface for persistent last-seen tracking';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE network_interface ADD last_auth_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD last_dhcp_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX idx_network_interface_last_auth_at ON network_interface (last_auth_at)');
        $this->addSql('CREATE INDEX idx_network_interface_last_dhcp_at ON network_interface (last_dhcp_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_network_interface_last_auth_at ON network_interface');
        $this->addSql('DROP INDEX idx_network_interface_last_dhcp_at ON network_interface');
        $this->addSql('ALTER TABLE network_interface DROP last_auth_at, DROP last_dhcp_at');
    }
}
