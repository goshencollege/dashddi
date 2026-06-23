<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260623140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fix is_canonical: promote first A and first AAAA record per interface when none is canonical (mirrors autoSetCanonical logic missed by data migration)';
    }

    public function up(Schema $schema): void
    {
        // The unification migration copied is_canonical = 0 for all migrated records
        // because the column was added to interface_name with DEFAULT 0 and users never
        // explicitly set it before unification ran.  This mirrors the autoSetCanonical()
        // controller logic: for each interface with no canonical A record, promote the
        // first (lowest-id) A record; same for AAAA.

        $this->addSql(<<<'SQL'
            UPDATE domain_record dr
            INNER JOIN (
                SELECT MIN(id) AS min_id, network_interface_id
                FROM domain_record
                WHERE network_interface_id IS NOT NULL
                  AND type = 'A'
                  AND domain_id IS NOT NULL
                GROUP BY network_interface_id
                HAVING SUM(is_canonical) = 0
            ) sub ON dr.id = sub.min_id
            SET dr.is_canonical = 1
            SQL
        );

        $this->addSql(<<<'SQL'
            UPDATE domain_record dr
            INNER JOIN (
                SELECT MIN(id) AS min_id, network_interface_id
                FROM domain_record
                WHERE network_interface_id IS NOT NULL
                  AND type = 'AAAA'
                  AND domain_id IS NOT NULL
                GROUP BY network_interface_id
                HAVING SUM(is_canonical) = 0
            ) sub ON dr.id = sub.min_id
            SET dr.is_canonical = 1
            SQL
        );
    }

    public function down(Schema $schema): void
    {
        // Reverse: clear is_canonical on all interface-linked records.
        // This undoes the promotion but cannot distinguish records that were
        // already canonical before this migration ran.
        $this->addSql(
            'UPDATE domain_record SET is_canonical = 0 WHERE network_interface_id IS NOT NULL'
        );
    }
}
