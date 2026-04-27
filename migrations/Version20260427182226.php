<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260427182226 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE address_block (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(20) NOT NULL, label VARCHAR(255) DEFAULT NULL, start_ip VARCHAR(45) NOT NULL, end_ip VARCHAR(45) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, created_by VARCHAR(255) DEFAULT NULL, updated_by VARCHAR(255) DEFAULT NULL, subnet_id INT NOT NULL, INDEX IDX_B4BEE002C9CF9478 (subnet_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE address_block ADD CONSTRAINT FK_B4BEE002C9CF9478 FOREIGN KEY (subnet_id) REFERENCES subnet (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE address_block DROP FOREIGN KEY FK_B4BEE002C9CF9478');
        $this->addSql('DROP TABLE address_block');
    }
}
