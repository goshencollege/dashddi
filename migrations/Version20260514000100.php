<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260514000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create radius_server table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE radius_server (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(255) NOT NULL,
            hostname VARCHAR(255) NOT NULL,
            ssh_user VARCHAR(64) NOT NULL DEFAULT 'root',
            remote_path VARCHAR(255) NOT NULL DEFAULT '/etc/freeradius',
            ssh_private_key LONGTEXT DEFAULT NULL,
            ssh_public_key LONGTEXT DEFAULT NULL,
            description LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            created_by VARCHAR(255) DEFAULT NULL,
            updated_by VARCHAR(255) DEFAULT NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE radius_server');
    }
}
