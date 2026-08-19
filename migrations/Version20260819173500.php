<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260819173500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed Purge Switch Port Logs scheduled task';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'INSERT INTO scheduled_task (name, task_key, description, cron_expression, enabled) SELECT ?, ?, ?, ?, 0 WHERE NOT EXISTS (SELECT 1 FROM scheduled_task WHERE task_key = ?)',
            ['Purge Switch Port Logs', 'purge_switch_port_logs', 'Deletes switch-port attachment log entries (from ClearPass auth logs and live switch scans) older than the retention period configured in Application Settings (default 90 days).', '0 3 * * 0', 'purge_switch_port_logs']
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM scheduled_task WHERE task_key = 'purge_switch_port_logs'");
    }
}
