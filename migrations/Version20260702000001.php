<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260702000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed Purge Activity Logs scheduled task';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'INSERT INTO scheduled_task (name, task_key, cron_expression, enabled) SELECT ?, ?, ?, 0 WHERE NOT EXISTS (SELECT 1 FROM scheduled_task WHERE task_key = ?)',
            ['Purge Activity Logs', 'purge_activity_logs', '0 3 * * 0', 'purge_activity_logs']
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM scheduled_task WHERE task_key = 'purge_activity_logs'");
    }
}
