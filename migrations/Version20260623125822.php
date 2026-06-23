<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260623125822 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Unify InterfaceName into DomainRecord: add network_interface_id + is_canonical, migrate data, drop interface_name table';
    }

    public function up(Schema $schema): void
    {
        // 1. Schema changes (idempotent — may already be applied if a prior run failed mid-way)
        $this->skipIf(
            !$schema->hasTable('interface_name'),
            'interface_name table already dropped; schema changes already applied'
        );

        $columns = array_column(
            $this->connection->executeQuery('DESCRIBE domain_record')->fetchAllAssociative(),
            'Field'
        );
        if (!in_array('is_canonical', $columns, true)) {
            $this->addSql('ALTER TABLE domain_record ADD is_canonical TINYINT DEFAULT 0 NOT NULL, ADD network_interface_id INT DEFAULT NULL');
            $this->addSql('ALTER TABLE domain_record ADD CONSTRAINT FK_F4579358CE793EEA FOREIGN KEY (network_interface_id) REFERENCES network_interface (id) ON DELETE CASCADE');
            $this->addSql('CREATE INDEX idx_domain_record_network_interface_id ON domain_record (network_interface_id)');
        }

        // 2. Data migration: copy interface_name rows to domain_record
        // Create A records for interface names whose interface has an IPv4 address
        $this->addSql(<<<'SQL'
            INSERT INTO domain_record
                (domain_id, network_interface_id, hostname, type, value, ttl, is_canonical, comment, created_at, updated_at, created_by, updated_by)
            SELECT
                n.domain_id,
                n.network_interface_id,
                n.name,
                'A',
                '',
                n.ttl,
                n.is_canonical,
                NULL,
                NOW(),
                NOW(),
                NULL,
                NULL
            FROM interface_name n
            INNER JOIN network_interface ni ON ni.id = n.network_interface_id
            INNER JOIN ip_address ip ON ip.id = ni.ip_address_id
            WHERE n.domain_id IS NOT NULL
            SQL
        );

        // Create AAAA records for interface names whose interface has an IPv6 address
        $this->addSql(<<<'SQL'
            INSERT INTO domain_record
                (domain_id, network_interface_id, hostname, type, value, ttl, is_canonical, comment, created_at, updated_at, created_by, updated_by)
            SELECT
                n.domain_id,
                n.network_interface_id,
                n.name,
                'AAAA',
                '',
                n.ttl,
                n.is_canonical,
                NULL,
                NOW(),
                NOW(),
                NULL,
                NULL
            FROM interface_name n
            INNER JOIN network_interface ni ON ni.id = n.network_interface_id
            INNER JOIN ipv6_address ip6 ON ip6.id = ni.ipv6_address_id
            WHERE n.domain_id IS NOT NULL
            SQL
        );

        // 3. Copy views: interface_name_dns_view → domain_record_dns_view
        // For A records
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO domain_record_dns_view (domain_record_id, dns_view_id)
            SELECT dr.id, inv.dns_view_id
            FROM domain_record dr
            INNER JOIN interface_name n
                ON n.network_interface_id = dr.network_interface_id
                AND n.domain_id = dr.domain_id
                AND n.name = dr.hostname
            INNER JOIN interface_name_dns_view inv ON inv.interface_name_id = n.id
            WHERE dr.network_interface_id IS NOT NULL AND dr.type = 'A'
            SQL
        );

        // For AAAA records
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO domain_record_dns_view (domain_record_id, dns_view_id)
            SELECT dr.id, inv.dns_view_id
            FROM domain_record dr
            INNER JOIN interface_name n
                ON n.network_interface_id = dr.network_interface_id
                AND n.domain_id = dr.domain_id
                AND n.name = dr.hostname
            INNER JOIN interface_name_dns_view inv ON inv.interface_name_id = n.id
            WHERE dr.network_interface_id IS NOT NULL AND dr.type = 'AAAA'
            SQL
        );

        // 4. Drop the now-redundant interface_name tables
        $this->addSql('DROP TABLE IF EXISTS interface_name_dns_view');
        $this->addSql('DROP TABLE IF EXISTS interface_name');
    }

    public function down(Schema $schema): void
    {
        // Recreate interface_name table (without data recovery)
        $this->addSql('CREATE TABLE interface_name (id INT AUTO_INCREMENT NOT NULL, domain_id INT DEFAULT NULL, network_interface_id INT NOT NULL, name VARCHAR(255) NOT NULL, ttl INT DEFAULT NULL, is_canonical TINYINT(1) DEFAULT 0 NOT NULL, INDEX idx_interface_name_network_interface_id (network_interface_id), INDEX idx_interface_name_domain_id (domain_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE interface_name_dns_view (interface_name_id INT NOT NULL, dns_view_id INT NOT NULL, INDEX IDX_interface_name_id (interface_name_id), INDEX IDX_dns_view_id (dns_view_id), PRIMARY KEY(interface_name_id, dns_view_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Remove interface-linked records from domain_record
        $this->addSql('DELETE FROM domain_record WHERE network_interface_id IS NOT NULL');
        $this->addSql('ALTER TABLE domain_record DROP FOREIGN KEY FK_F4579358CE793EEA');
        $this->addSql('DROP INDEX idx_domain_record_network_interface_id ON domain_record');
        $this->addSql('ALTER TABLE domain_record DROP is_canonical, DROP network_interface_id');
    }
}
