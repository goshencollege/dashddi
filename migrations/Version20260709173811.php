<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260709173811 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE network_interface ADD switch_ip VARCHAR(45) DEFAULT NULL, ADD switch_port VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_network_interface_switch_ip ON network_interface (switch_ip)');
        $this->addSql('CREATE INDEX idx_network_interface_switch_port ON network_interface (switch_port)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_network_interface_switch_ip ON network_interface');
        $this->addSql('DROP INDEX idx_network_interface_switch_port ON network_interface');
        $this->addSql('ALTER TABLE network_interface DROP switch_ip, DROP switch_port');
    }
}
