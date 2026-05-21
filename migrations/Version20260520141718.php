<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260520141718 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE aruba_switch (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, management_ip VARCHAR(255) NOT NULL, username VARCHAR(128) NOT NULL, password LONGTEXT DEFAULT NULL, ssh_private_key LONGTEXT DEFAULT NULL, ssh_public_key LONGTEXT DEFAULT NULL, rest_api_version VARCHAR(20) DEFAULT \'v10.12\' NOT NULL, verify_tls TINYINT DEFAULT 0 NOT NULL, description LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, created_by VARCHAR(255) DEFAULT NULL, updated_by VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE aruba_switch');
    }
}
