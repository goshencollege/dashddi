<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260514000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed default Push RADIUS Configs scheduled task';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'INSERT INTO scheduled_task (name, task_key, cron_expression, enabled) SELECT ?, ?, ?, 0 WHERE NOT EXISTS (SELECT 1 FROM scheduled_task WHERE task_key = ?)',
            ['Push RADIUS Configs', 'push_radius', '0 2 * * *', 'push_radius']
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM scheduled_task WHERE task_key = 'push_radius'");
    }
}
