<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260504110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create app_setting table for global application settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_setting (
            id INT NOT NULL,
            default_lease_retention_days INT DEFAULT NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_setting');
    }
}
