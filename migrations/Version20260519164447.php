<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260519164447 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add soft-delete (deleted_at) to host and network_interface; add deleted_host_retention_days setting; add Purge Deleted Hosts scheduled task';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_setting ADD deleted_host_retention_days INT DEFAULT 90');
        $this->addSql('ALTER TABLE host ADD deleted_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE network_interface ADD deleted_at DATETIME DEFAULT NULL');

        $this->addSql("INSERT INTO scheduled_task (name, task_key, description, cron_expression, enabled, last_run_at, last_run_status, last_run_output, notification_email)
            VALUES (
                'Purge Deleted Hosts',
                'purge_deleted_hosts',
                'Hard-deletes hosts and interfaces that were soft-deleted more than the configured retention period ago (default 90 days). Configured in Application Settings.',
                '0 3 * * 0',
                0,
                NULL, NULL, NULL, NULL
            )");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_setting DROP deleted_host_retention_days');
        $this->addSql('ALTER TABLE host DROP deleted_at');
        $this->addSql('ALTER TABLE network_interface DROP deleted_at');
        $this->addSql("DELETE FROM scheduled_task WHERE task_key = 'purge_deleted_hosts'");
    }
}
