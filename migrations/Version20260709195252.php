<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260709195252 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add dhcp_server_id FK to dhcp_lease to track which server reported each lease';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dhcp_lease ADD dhcp_server_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE dhcp_lease ADD CONSTRAINT FK_B30CA119786B311 FOREIGN KEY (dhcp_server_id) REFERENCES dhcp_server (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_dhcp_lease_server_id ON dhcp_lease (dhcp_server_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dhcp_lease DROP FOREIGN KEY FK_B30CA119786B311');
        $this->addSql('DROP INDEX idx_dhcp_lease_server_id ON dhcp_lease');
        $this->addSql('ALTER TABLE dhcp_lease DROP dhcp_server_id');
    }
}
