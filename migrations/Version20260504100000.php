<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260504100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create scheduled_task table with default tasks';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE scheduled_task (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(100) NOT NULL,
            task_key VARCHAR(50) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            cron_expression VARCHAR(100) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            last_run_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            last_run_status VARCHAR(20) DEFAULT NULL,
            last_run_output LONGTEXT DEFAULT NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql("INSERT INTO scheduled_task (name, task_key, description, cron_expression, enabled) VALUES
            ('Push DNS Configs',
             'push_dns',
             'Generates BIND zone files and views.conf, then deploys them to all configured DNS servers.',
             '0 2 * * *',
             0),
            ('Push DHCP Configs',
             'push_dhcp',
             'Generates Kea DHCP subnet configuration and deploys it to all configured DHCP servers, then reloads Kea.',
             '0 2 * * *',
             0),
            ('Purge DHCP Lease Logs',
             'purge_leases',
             'Deletes DHCP lease log entries that exceed each subnet\'s configured retention period.',
             '0 3 * * 0',
             0)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE scheduled_task');
    }
}
