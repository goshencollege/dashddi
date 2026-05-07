<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260507150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create saml_provider table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE saml_provider (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(255) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 0,
            sp_entity_id VARCHAR(255) NOT NULL,
            sp_acs_url VARCHAR(255) NOT NULL,
            sp_slo_url VARCHAR(255) NOT NULL,
            sp_cert LONGTEXT NOT NULL,
            sp_private_key LONGTEXT NOT NULL,
            idp_entity_id VARCHAR(255) NOT NULL,
            idp_sso_url VARCHAR(255) NOT NULL,
            idp_cert LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE saml_provider');
    }
}
