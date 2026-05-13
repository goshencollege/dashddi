<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260513010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create push_log table for worker push history';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE push_log (
            id INT AUTO_INCREMENT NOT NULL,
            type VARCHAR(4) NOT NULL,
            server_name VARCHAR(255) NOT NULL,
            success TINYINT(1) NOT NULL,
            result JSON NOT NULL,
            error_message LONGTEXT DEFAULT NULL,
            started_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            completed_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            INDEX idx_push_log_started (started_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE push_log');
    }
}
