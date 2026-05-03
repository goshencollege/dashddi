<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260502100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add dhcp_lease table and lease_retention_days to subnet';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE dhcp_lease (
            id INT AUTO_INCREMENT NOT NULL,
            subnet_id INT DEFAULT NULL,
            mac_address VARCHAR(17) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            hostname VARCHAR(255) DEFAULT NULL,
            lease_start DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            lease_expires DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_dhcp_lease_mac (mac_address),
            INDEX idx_dhcp_lease_ip (ip_address),
            INDEX idx_dhcp_lease_subnet (subnet_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE dhcp_lease ADD CONSTRAINT fk_dhcp_lease_subnet
            FOREIGN KEY (subnet_id) REFERENCES subnet (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE subnet ADD lease_retention_days INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dhcp_lease DROP FOREIGN KEY fk_dhcp_lease_subnet');
        $this->addSql('DROP TABLE dhcp_lease');
        $this->addSql('ALTER TABLE subnet DROP COLUMN lease_retention_days');
    }
}
