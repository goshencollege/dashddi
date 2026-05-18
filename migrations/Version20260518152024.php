<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260518152024 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove FreeRADIUS tables and scheduled task';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DELETE FROM push_log WHERE type = \'radius\'');
        $this->addSql('DELETE FROM scheduled_task WHERE task_key = \'push_radius\'');
        $this->addSql('DROP TABLE IF EXISTS radius_client');
        $this->addSql('DROP TABLE IF EXISTS radius_server');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE radius_server (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, hostname VARCHAR(255) NOT NULL, ssh_user VARCHAR(100) NOT NULL, remote_path VARCHAR(500) NOT NULL, ssh_private_key LONGTEXT DEFAULT NULL, ssh_public_key LONGTEXT DEFAULT NULL, description LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_by VARCHAR(255) DEFAULT NULL, updated_by VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE radius_client (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, nasname VARCHAR(255) NOT NULL, shortname VARCHAR(255) DEFAULT NULL, secret LONGTEXT NOT NULL, description LONGTEXT DEFAULT NULL, enabled TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_by VARCHAR(255) DEFAULT NULL, updated_by VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('INSERT INTO scheduled_task (task_key, cron_expression, enabled) VALUES (\'push_radius\', \'0 2 * * *\', 0)');
    }
}
