<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Pull ClearPass Auth Logs and Purge ClearPass Auth Logs scheduled tasks';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT INTO scheduled_task (name, task_key, description, cron_expression, enabled) VALUES
            ('Pull ClearPass Auth Logs',
             'pull_clearpass_logs',
             'Pulls authentication session logs from all configured ClearPass servers using the REST API. On first run, imports the last 24 hours. Subsequent runs pick up where the previous left off.',
             '*/15 * * * *',
             0)
        ");

        $this->addSql("INSERT INTO scheduled_task (name, task_key, description, cron_expression, enabled) VALUES
            ('Purge ClearPass Auth Logs',
             'purge_clearpass_auth_logs',
             'Deletes ClearPass authentication log entries older than the retention period configured in Application Settings (default 90 days).',
             '0 3 * * 0',
             0)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM scheduled_task WHERE task_key IN ('pull_clearpass_logs', 'purge_clearpass_auth_logs')");
    }
}
