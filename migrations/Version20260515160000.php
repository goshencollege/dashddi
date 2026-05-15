<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Push ClearPass Endpoints scheduled task';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT INTO scheduled_task (name, task_key, description, cron_expression, enabled) VALUES
            ('Push ClearPass Endpoints',
             'push_clearpass',
             'Syncs interface data to the endpoint repository on all configured ClearPass servers. Creates, updates, and removes only endpoints managed by DashDDI.',
             '0 2 * * *',
             0)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM scheduled_task WHERE task_key = 'push_clearpass'");
    }
}
