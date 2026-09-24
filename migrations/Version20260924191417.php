<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260924191417 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add snipe_it_managed to network_interface so Snipe-IT sync only ever removes interfaces it created itself, never manually-added ones';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE network_interface ADD snipe_it_managed TINYINT(1) DEFAULT 0 NOT NULL');
        // Backfill: every active interface already on a host linked to Snipe-IT was, under the
        // old behavior, treated as sync-owned (it would have been deleted if its MAC dropped out
        // of the asset). Mark them managed so cleanup of genuinely stale synced NICs keeps working;
        // anything added after this migration defaults to unmanaged (manual) and is left alone.
        $this->addSql('
            UPDATE network_interface ni
            INNER JOIN snipe_it_asset_link sal ON sal.host_id = ni.host_id
            SET ni.snipe_it_managed = 1
            WHERE ni.deleted_at IS NULL
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE network_interface DROP snipe_it_managed');
    }
}
