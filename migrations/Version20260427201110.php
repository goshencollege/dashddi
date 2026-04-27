<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260427201110 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE domain (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, created_by VARCHAR(255) DEFAULT NULL, updated_by VARCHAR(255) DEFAULT NULL, UNIQUE INDEX UNIQ_A7A91E0B5E237E06 (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE domain_record (id INT AUTO_INCREMENT NOT NULL, hostname VARCHAR(255) NOT NULL, type VARCHAR(10) NOT NULL, value LONGTEXT NOT NULL, ttl INT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, created_by VARCHAR(255) DEFAULT NULL, updated_by VARCHAR(255) DEFAULT NULL, domain_id INT NOT NULL, INDEX IDX_F4579358115F0EE5 (domain_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE domain_record ADD CONSTRAINT FK_F4579358115F0EE5 FOREIGN KEY (domain_id) REFERENCES domain (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE interface_name ADD domain_id INT DEFAULT NULL, DROP dns_domain');
        $this->addSql('ALTER TABLE interface_name ADD CONSTRAINT FK_E72D638E115F0EE5 FOREIGN KEY (domain_id) REFERENCES domain (id)');
        $this->addSql('CREATE INDEX IDX_E72D638E115F0EE5 ON interface_name (domain_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE domain_record DROP FOREIGN KEY FK_F4579358115F0EE5');
        $this->addSql('DROP TABLE domain');
        $this->addSql('DROP TABLE domain_record');
        $this->addSql('ALTER TABLE interface_name DROP FOREIGN KEY FK_E72D638E115F0EE5');
        $this->addSql('DROP INDEX IDX_E72D638E115F0EE5 ON interface_name');
        $this->addSql('ALTER TABLE interface_name ADD dns_domain VARCHAR(255) NOT NULL, DROP domain_id');
    }
}
