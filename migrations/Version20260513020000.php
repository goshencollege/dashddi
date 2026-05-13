<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260513020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add push_log_retention_days to app_setting; add Purge Push Logs scheduled task';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_setting ADD push_log_retention_days INT DEFAULT 30');

        $this->addSql("INSERT INTO scheduled_task (name, task_key, description, cron_expression, enabled, last_run_at, last_run_status, last_run_output, notification_email)
            VALUES (
                'Purge Push Logs',
                'purge_push_logs',
                'Deletes DNS/DHCP push log entries older than the retention period configured in Application Settings (default 30 days).',
                '0 3 * * 0',
                0,
                NULL, NULL, NULL, NULL
            )");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_setting DROP COLUMN push_log_retention_days');
        $this->addSql("DELETE FROM scheduled_task WHERE task_key = 'purge_push_logs'");
    }
}
