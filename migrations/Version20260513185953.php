<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260513185953 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed default scheduled tasks';
    }

    public function up(Schema $schema): void
    {
        $tasks = [
            ['Push DNS Configs',      'push_dns',        '0 2 * * *'],
            ['Push DHCP Configs',     'push_dhcp',       '0 2 * * *'],
            ['Purge DHCP Lease Logs', 'purge_leases',    '0 3 * * 0'],
            ['Purge Push Logs',       'purge_push_logs', '0 3 * * 0'],
            ['Database Backup',       'database_backup', '0 2 * * *'],
        ];

        foreach ($tasks as [$name, $taskKey, $cron]) {
            $this->addSql(
                'INSERT INTO scheduled_task (name, task_key, cron_expression, enabled) SELECT ?, ?, ?, 0 WHERE NOT EXISTS (SELECT 1 FROM scheduled_task WHERE task_key = ?)',
                [$name, $taskKey, $cron, $taskKey]
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM scheduled_task WHERE task_key IN ('push_dns', 'push_dhcp', 'purge_leases', 'purge_push_logs', 'database_backup')");
    }
}
