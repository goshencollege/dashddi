<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260519151853 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add snipe_it_category_subnet_map table for default subnet assignment during Snipe-IT sync';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE snipe_it_category_subnet_map (id INT AUTO_INCREMENT NOT NULL, snipe_category_id INT NOT NULL, snipe_category_name VARCHAR(255) NOT NULL, server_id INT NOT NULL, subnet_id INT DEFAULT NULL, INDEX IDX_6F49BDB61844E6B7 (server_id), INDEX IDX_6F49BDB6C9CF9478 (subnet_id), UNIQUE INDEX uq_server_category (server_id, snipe_category_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE snipe_it_category_subnet_map ADD CONSTRAINT FK_6F49BDB61844E6B7 FOREIGN KEY (server_id) REFERENCES snipe_it_server (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE snipe_it_category_subnet_map ADD CONSTRAINT FK_6F49BDB6C9CF9478 FOREIGN KEY (subnet_id) REFERENCES subnet (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE snipe_it_category_subnet_map DROP FOREIGN KEY FK_6F49BDB61844E6B7');
        $this->addSql('ALTER TABLE snipe_it_category_subnet_map DROP FOREIGN KEY FK_6F49BDB6C9CF9478');
        $this->addSql('DROP TABLE snipe_it_category_subnet_map');
    }
}
