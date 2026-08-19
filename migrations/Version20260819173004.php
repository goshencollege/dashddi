<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260819173004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add switch_port_log table and app_setting.switch_port_log_retention_days';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE switch_port_log (id INT AUTO_INCREMENT NOT NULL, source VARCHAR(20) NOT NULL, switch_ip VARCHAR(45) NOT NULL, switch_port VARCHAR(255) NOT NULL, observed_at DATETIME NOT NULL, created_at DATETIME NOT NULL, network_interface_id INT NOT NULL, INDEX idx_switch_port_log_iface (network_interface_id), INDEX idx_switch_port_log_observed_at (observed_at), INDEX idx_switch_port_log_source (source), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE switch_port_log ADD CONSTRAINT FK_3F9113EACE793EEA FOREIGN KEY (network_interface_id) REFERENCES network_interface (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE app_setting ADD switch_port_log_retention_days INT DEFAULT 90');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE switch_port_log DROP FOREIGN KEY FK_3F9113EACE793EEA');
        $this->addSql('DROP TABLE switch_port_log');
        $this->addSql('ALTER TABLE app_setting DROP switch_port_log_retention_days');
    }
}
