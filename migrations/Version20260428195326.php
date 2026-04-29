<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260428195326 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE dns_server (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, hostname VARCHAR(255) NOT NULL, ssh_user VARCHAR(64) NOT NULL, ssh_key_path VARCHAR(255) NOT NULL, remote_zone_path VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, created_by VARCHAR(255) DEFAULT NULL, updated_by VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE dns_server_dns_view (dns_server_id INT NOT NULL, dns_view_id INT NOT NULL, INDEX IDX_C0A729EDD1CF5272 (dns_server_id), INDEX IDX_C0A729ED9FF5B956 (dns_view_id), PRIMARY KEY (dns_server_id, dns_view_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE dns_server_dns_view ADD CONSTRAINT FK_C0A729EDD1CF5272 FOREIGN KEY (dns_server_id) REFERENCES dns_server (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE dns_server_dns_view ADD CONSTRAINT FK_C0A729ED9FF5B956 FOREIGN KEY (dns_view_id) REFERENCES dns_view (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dns_server_dns_view DROP FOREIGN KEY FK_C0A729EDD1CF5272');
        $this->addSql('ALTER TABLE dns_server_dns_view DROP FOREIGN KEY FK_C0A729ED9FF5B956');
        $this->addSql('DROP TABLE dns_server');
        $this->addSql('DROP TABLE dns_server_dns_view');
    }
}
