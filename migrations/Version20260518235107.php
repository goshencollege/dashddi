<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260518235107 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed Pull Snipe-IT Assets scheduled task';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'INSERT INTO scheduled_task (name, task_key, cron_expression, enabled) SELECT ?, ?, ?, 0 WHERE NOT EXISTS (SELECT 1 FROM scheduled_task WHERE task_key = ?)',
            ['Pull Snipe-IT Assets', 'pull_snipe_it', '0 3 * * *', 'pull_snipe_it']
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM scheduled_task WHERE task_key = 'pull_snipe_it'");
    }
}
