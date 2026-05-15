<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add clearpass_server table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE clearpass_server (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(255) NOT NULL,
            api_url VARCHAR(255) NOT NULL,
            client_id VARCHAR(255) NOT NULL,
            client_secret VARCHAR(255) NOT NULL,
            verify_tls TINYINT(1) NOT NULL DEFAULT 1,
            description LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            created_by VARCHAR(255) DEFAULT NULL,
            updated_by VARCHAR(255) DEFAULT NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE clearpass_server');
    }
}
