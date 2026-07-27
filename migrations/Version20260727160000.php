<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260727160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add host_id to activity_log for efficient host-scoped log filtering';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE activity_log ADD host_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_activity_log_host ON activity_log (host_id)');

        // Backfill existing rows where the entity is still resolvable.
        // Rows for hard-deleted entities are unrecoverable and remain NULL.

        // Host events — host_id is the entity itself
        $this->addSql(
            "UPDATE activity_log SET host_id = entity_id
             WHERE entity_type = 'Host' AND entity_id IS NOT NULL"
        );

        // NetworkInterface events — follow the host_id FK
        $this->addSql(
            "UPDATE activity_log al
             INNER JOIN network_interface ni ON ni.id = al.entity_id
             SET al.host_id = ni.host_id
             WHERE al.entity_type = 'NetworkInterface' AND al.entity_id IS NOT NULL"
        );

        // ApiToken events — follow the nullable host_id FK
        $this->addSql(
            "UPDATE activity_log al
             INNER JOIN api_token at ON at.id = al.entity_id
             SET al.host_id = at.host_id
             WHERE al.entity_type = 'ApiToken' AND al.entity_id IS NOT NULL AND at.host_id IS NOT NULL"
        );

        // DomainRecord events via network_interface → host
        $this->addSql(
            "UPDATE activity_log al
             INNER JOIN domain_record dr ON dr.id = al.entity_id
             INNER JOIN network_interface ni ON ni.id = dr.network_interface_id
             SET al.host_id = ni.host_id
             WHERE al.entity_type = 'DomainRecord' AND al.entity_id IS NOT NULL AND dr.network_interface_id IS NOT NULL"
        );

        // DomainRecord events via virtual_ip → first member interface → host
        $this->addSql(
            "UPDATE activity_log al
             INNER JOIN domain_record dr ON dr.id = al.entity_id
             INNER JOIN (
                 SELECT vini.virtual_ip_id, MIN(ni.host_id) AS host_id
                 FROM virtual_ip_network_interface vini
                 INNER JOIN network_interface ni ON ni.id = vini.network_interface_id
                 WHERE ni.host_id IS NOT NULL
                 GROUP BY vini.virtual_ip_id
             ) viphosts ON viphosts.virtual_ip_id = dr.virtual_ip_id
             SET al.host_id = viphosts.host_id
             WHERE al.entity_type = 'DomainRecord' AND al.entity_id IS NOT NULL AND al.host_id IS NULL AND dr.virtual_ip_id IS NOT NULL"
        );

        // VirtualIp events — use first member interface's host
        $this->addSql(
            "UPDATE activity_log al
             INNER JOIN (
                 SELECT vini.virtual_ip_id, MIN(ni.host_id) AS host_id
                 FROM virtual_ip_network_interface vini
                 INNER JOIN network_interface ni ON ni.id = vini.network_interface_id
                 WHERE ni.host_id IS NOT NULL
                 GROUP BY vini.virtual_ip_id
             ) viphosts ON viphosts.virtual_ip_id = al.entity_id
             SET al.host_id = viphosts.host_id
             WHERE al.entity_type = 'VirtualIp' AND al.entity_id IS NOT NULL"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_activity_log_host ON activity_log');
        $this->addSql('ALTER TABLE activity_log DROP host_id');
    }
}
