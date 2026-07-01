<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260701162341 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE activity_log (id INT AUTO_INCREMENT NOT NULL, action VARCHAR(16) NOT NULL, entity_type VARCHAR(64) DEFAULT NULL, entity_id INT DEFAULT NULL, entity_label VARCHAR(255) NOT NULL, user_identifier VARCHAR(255) DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, changed_fields JSON DEFAULT NULL, created_at DATETIME NOT NULL, INDEX idx_activity_log_created (created_at), INDEX idx_activity_log_user (user_identifier), INDEX idx_activity_log_type (entity_type), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE app_setting ADD activity_log_retention_days INT DEFAULT 90, ADD syslog_enabled TINYINT DEFAULT 0 NOT NULL, ADD syslog_host VARCHAR(255) DEFAULT NULL, ADD syslog_port INT DEFAULT 514, ADD syslog_protocol VARCHAR(3) DEFAULT \'udp\'');
        $this->addSql('ALTER TABLE backup_setting ADD exclude_activity_log TINYINT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE activity_log');
        $this->addSql('ALTER TABLE app_setting DROP activity_log_retention_days, DROP syslog_enabled, DROP syslog_host, DROP syslog_port, DROP syslog_protocol');
        $this->addSql('ALTER TABLE backup_setting DROP exclude_activity_log');
    }
}
