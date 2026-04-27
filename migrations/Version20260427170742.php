<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260427170742 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE host (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, location VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE interface_name (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, dns_domain VARCHAR(255) NOT NULL, network_interface_id INT NOT NULL, INDEX IDX_E72D638ECE793EEA (network_interface_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ip_address (id INT AUTO_INCREMENT NOT NULL, address VARCHAR(50) NOT NULL, subnet_id INT NOT NULL, INDEX IDX_22FFD58CC9CF9478 (subnet_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ipv6_address (id INT AUTO_INCREMENT NOT NULL, address VARCHAR(100) NOT NULL, subnet_id INT NOT NULL, INDEX IDX_8A54DF17C9CF9478 (subnet_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE network_interface (id INT AUTO_INCREMENT NOT NULL, mac_address VARCHAR(17) NOT NULL, host_id INT NOT NULL, subnet_id INT DEFAULT NULL, ip_address_id INT DEFAULT NULL, ipv6_address_id INT DEFAULT NULL, INDEX IDX_B3518C341FB8D185 (host_id), INDEX IDX_B3518C34C9CF9478 (subnet_id), UNIQUE INDEX UNIQ_B3518C345F23F921 (ip_address_id), UNIQUE INDEX UNIQ_B3518C34158E3C72 (ipv6_address_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE subnet (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, ipv4_cidr VARCHAR(50) DEFAULT NULL, ipv6_cidr VARCHAR(100) DEFAULT NULL, description LONGTEXT DEFAULT NULL, vlan INT DEFAULT NULL, gateway VARCHAR(50) DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE interface_name ADD CONSTRAINT FK_E72D638ECE793EEA FOREIGN KEY (network_interface_id) REFERENCES network_interface (id)');
        $this->addSql('ALTER TABLE ip_address ADD CONSTRAINT FK_22FFD58CC9CF9478 FOREIGN KEY (subnet_id) REFERENCES subnet (id)');
        $this->addSql('ALTER TABLE ipv6_address ADD CONSTRAINT FK_8A54DF17C9CF9478 FOREIGN KEY (subnet_id) REFERENCES subnet (id)');
        $this->addSql('ALTER TABLE network_interface ADD CONSTRAINT FK_B3518C341FB8D185 FOREIGN KEY (host_id) REFERENCES host (id)');
        $this->addSql('ALTER TABLE network_interface ADD CONSTRAINT FK_B3518C34C9CF9478 FOREIGN KEY (subnet_id) REFERENCES subnet (id)');
        $this->addSql('ALTER TABLE network_interface ADD CONSTRAINT FK_B3518C345F23F921 FOREIGN KEY (ip_address_id) REFERENCES ip_address (id)');
        $this->addSql('ALTER TABLE network_interface ADD CONSTRAINT FK_B3518C34158E3C72 FOREIGN KEY (ipv6_address_id) REFERENCES ipv6_address (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE interface_name DROP FOREIGN KEY FK_E72D638ECE793EEA');
        $this->addSql('ALTER TABLE ip_address DROP FOREIGN KEY FK_22FFD58CC9CF9478');
        $this->addSql('ALTER TABLE ipv6_address DROP FOREIGN KEY FK_8A54DF17C9CF9478');
        $this->addSql('ALTER TABLE network_interface DROP FOREIGN KEY FK_B3518C341FB8D185');
        $this->addSql('ALTER TABLE network_interface DROP FOREIGN KEY FK_B3518C34C9CF9478');
        $this->addSql('ALTER TABLE network_interface DROP FOREIGN KEY FK_B3518C345F23F921');
        $this->addSql('ALTER TABLE network_interface DROP FOREIGN KEY FK_B3518C34158E3C72');
        $this->addSql('DROP TABLE host');
        $this->addSql('DROP TABLE interface_name');
        $this->addSql('DROP TABLE ip_address');
        $this->addSql('DROP TABLE ipv6_address');
        $this->addSql('DROP TABLE network_interface');
        $this->addSql('DROP TABLE subnet');
    }
}
