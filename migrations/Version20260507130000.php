<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260507130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create api_token table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE api_token (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(255) NOT NULL,
            token_hash VARCHAR(64) NOT NULL,
            owner_identifier VARCHAR(255) NOT NULL,
            allowed_routes JSON NOT NULL,
            expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            last_used_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX UNIQ_API_TOKEN_HASH (token_hash),
            INDEX IDX_API_TOKEN_OWNER (owner_identifier),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE api_token');
    }
}
