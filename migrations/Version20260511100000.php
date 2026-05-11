<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260511100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create backup_setting table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE backup_setting (
            id INT NOT NULL,
            destination_type VARCHAR(10) NOT NULL DEFAULT 'local',
            local_path VARCHAR(500) DEFAULT NULL,
            cifs_server VARCHAR(255) DEFAULT NULL,
            cifs_username VARCHAR(255) DEFAULT NULL,
            cifs_password VARCHAR(500) DEFAULT NULL,
            cifs_subdir VARCHAR(255) DEFAULT NULL,
            decrypt_fields TINYINT(1) NOT NULL DEFAULT 0,
            include_encryption_key TINYINT(1) NOT NULL DEFAULT 0,
            encrypt_backup TINYINT(1) NOT NULL DEFAULT 0,
            backup_password VARCHAR(500) DEFAULT NULL,
            retention_count INT NOT NULL DEFAULT 10,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE backup_setting');
    }
}
