<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260513040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create radius_client table for FreeRADIUS NAC integration';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE radius_client (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(255) NOT NULL,
            nasname VARCHAR(128) NOT NULL,
            shortname VARCHAR(32) DEFAULT NULL,
            secret LONGTEXT NOT NULL,
            description LONGTEXT DEFAULT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            created_by VARCHAR(255) DEFAULT NULL,
            updated_by VARCHAR(255) DEFAULT NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE radius_client');
    }
}
