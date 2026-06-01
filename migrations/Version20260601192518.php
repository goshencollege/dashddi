<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260601192518 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE subnet_record (id INT AUTO_INCREMENT NOT NULL, hostname VARCHAR(255) NOT NULL, type VARCHAR(10) NOT NULL, value LONGTEXT NOT NULL, ttl INT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, created_by VARCHAR(255) DEFAULT NULL, updated_by VARCHAR(255) DEFAULT NULL, subnet_id INT NOT NULL, INDEX idx_subnet_record_subnet_id (subnet_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE subnet_record_dns_view (subnet_record_id INT NOT NULL, dns_view_id INT NOT NULL, INDEX IDX_6C0A3B7AAB8933FD (subnet_record_id), INDEX IDX_6C0A3B7A9FF5B956 (dns_view_id), PRIMARY KEY (subnet_record_id, dns_view_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE subnet_record ADD CONSTRAINT FK_B2B047FBC9CF9478 FOREIGN KEY (subnet_id) REFERENCES subnet (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE subnet_record_dns_view ADD CONSTRAINT FK_6C0A3B7AAB8933FD FOREIGN KEY (subnet_record_id) REFERENCES subnet_record (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE subnet_record_dns_view ADD CONSTRAINT FK_6C0A3B7A9FF5B956 FOREIGN KEY (dns_view_id) REFERENCES dns_view (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE subnet_record DROP FOREIGN KEY FK_B2B047FBC9CF9478');
        $this->addSql('ALTER TABLE subnet_record_dns_view DROP FOREIGN KEY FK_6C0A3B7AAB8933FD');
        $this->addSql('ALTER TABLE subnet_record_dns_view DROP FOREIGN KEY FK_6C0A3B7A9FF5B956');
        $this->addSql('DROP TABLE subnet_record');
        $this->addSql('DROP TABLE subnet_record_dns_view');
    }
}
