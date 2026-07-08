<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260708133348 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE virtual_ip (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(255) NOT NULL, protocol VARCHAR(20) NOT NULL, vrid INT DEFAULT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, created_by VARCHAR(255) DEFAULT NULL, updated_by VARCHAR(255) DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, subnet_id INT NOT NULL, ip_address_id INT DEFAULT NULL, ipv6_address_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_13D88AE85F23F921 (ip_address_id), UNIQUE INDEX UNIQ_13D88AE8158E3C72 (ipv6_address_id), INDEX idx_virtual_ip_deleted_at (deleted_at), INDEX idx_virtual_ip_subnet_id (subnet_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE virtual_ip_network_interface (virtual_ip_id INT NOT NULL, network_interface_id INT NOT NULL, INDEX IDX_1B0DE15188D46E1C (virtual_ip_id), INDEX IDX_1B0DE151CE793EEA (network_interface_id), PRIMARY KEY (virtual_ip_id, network_interface_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE virtual_ip ADD CONSTRAINT FK_13D88AE8C9CF9478 FOREIGN KEY (subnet_id) REFERENCES subnet (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE virtual_ip ADD CONSTRAINT FK_13D88AE85F23F921 FOREIGN KEY (ip_address_id) REFERENCES ip_address (id)');
        $this->addSql('ALTER TABLE virtual_ip ADD CONSTRAINT FK_13D88AE8158E3C72 FOREIGN KEY (ipv6_address_id) REFERENCES ipv6_address (id)');
        $this->addSql('ALTER TABLE virtual_ip_network_interface ADD CONSTRAINT FK_1B0DE15188D46E1C FOREIGN KEY (virtual_ip_id) REFERENCES virtual_ip (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE virtual_ip_network_interface ADD CONSTRAINT FK_1B0DE151CE793EEA FOREIGN KEY (network_interface_id) REFERENCES network_interface (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE domain_record ADD virtual_ip_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE domain_record ADD CONSTRAINT FK_F457935888D46E1C FOREIGN KEY (virtual_ip_id) REFERENCES virtual_ip (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_domain_record_virtual_ip_id ON domain_record (virtual_ip_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE virtual_ip DROP FOREIGN KEY FK_13D88AE8C9CF9478');
        $this->addSql('ALTER TABLE virtual_ip DROP FOREIGN KEY FK_13D88AE85F23F921');
        $this->addSql('ALTER TABLE virtual_ip DROP FOREIGN KEY FK_13D88AE8158E3C72');
        $this->addSql('ALTER TABLE virtual_ip_network_interface DROP FOREIGN KEY FK_1B0DE15188D46E1C');
        $this->addSql('ALTER TABLE virtual_ip_network_interface DROP FOREIGN KEY FK_1B0DE151CE793EEA');
        $this->addSql('DROP TABLE virtual_ip');
        $this->addSql('DROP TABLE virtual_ip_network_interface');
        $this->addSql('ALTER TABLE domain_record DROP FOREIGN KEY FK_F457935888D46E1C');
        $this->addSql('DROP INDEX idx_domain_record_virtual_ip_id ON domain_record');
        $this->addSql('ALTER TABLE domain_record DROP virtual_ip_id');
    }
}
