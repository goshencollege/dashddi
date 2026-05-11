<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260511110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed default Database Backup scheduled task (disabled)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT INTO scheduled_task (name, task_key, description, cron_expression, enabled)
            VALUES (
                'Database Backup',
                'database_backup',
                'Creates a database backup using the destination and options configured in Backup Settings.',
                '0 2 * * *',
                0
            )");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM scheduled_task WHERE task_key = 'database_backup'");
    }
}
